<?php

use App\Domain\Exchange\BinanceSpotClient;
use Illuminate\Support\Facades\Http;

function binanceSpotTestClient(): BinanceSpotClient
{
    return new BinanceSpotClient('key', 'secret', 'https://api.binance.com', 'wss://stream.binance.com:9443');
}

function binanceSpotResolveFillPrice(BinanceSpotClient $client, array $payload): float
{
    $method = new ReflectionMethod(BinanceSpotClient::class, 'resolveFillPrice');
    $method->setAccessible(true);

    return $method->invoke($client, $payload);
}

it('resolves fill price from a fills-weighted average when fills are present', function () {
    $client = binanceSpotTestClient();

    $price = binanceSpotResolveFillPrice($client, [
        'fills' => [
            ['qty' => '60', 'price' => '1.0'],
            ['qty' => '40', 'price' => '0.5'],
        ],
    ]);

    // (60*1.0 + 40*0.5) / 100 = 0.8
    expect($price)->toEqual(0.8);
});

it('falls back to cummulativeQuoteQty/executedQty when fills are empty', function () {
    $client = binanceSpotTestClient();

    $price = binanceSpotResolveFillPrice($client, [
        'fills' => [],
        'executedQty' => '10',
        'cummulativeQuoteQty' => '500',
    ]);

    expect($price)->toEqual(50.0);
});

it('treats "0.00" price/avgPrice as missing rather than a real price', function () {
    $client = binanceSpotTestClient();

    $price = binanceSpotResolveFillPrice($client, [
        'fills' => [],
        'executedQty' => '10',
        'cummulativeQuoteQty' => '0',
        'avgPrice' => '0.00',
        'price' => '0.00',
    ]);

    expect($price)->toEqual(0.0);
});

it('resolves a testnet 0-price market order via re-fetch, never throwing', function () {
    Http::fake(function ($request) {
        if ($request->method() === 'POST' && str_contains($request->url(), '/api/v3/order')) {
            return Http::response([
                'orderId' => 555,
                'status' => 'FILLED',
                'symbol' => 'BTCUSDT',
                'side' => 'BUY',
                'type' => 'MARKET',
                'executedQty' => '0.001',
                'price' => '0.00', // testnet quirk: no real price on the ack
                'cummulativeQuoteQty' => '0.00',
                'fills' => [],
            ]);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/api/v3/order')) {
            // Re-fetch by orderId succeeds with the real fill data.
            return Http::response([
                'orderId' => 555,
                'status' => 'FILLED',
                'executedQty' => '0.001',
                'cummulativeQuoteQty' => '65.4321',
            ]);
        }

        return Http::response([], 404);
    });

    $client = binanceSpotTestClient();
    $order = $client->placeMarketOrder('BTCUSDT', 'BUY', 0.001, 'test-client-id');

    expect($order->price)->toEqual(65432.1)
        ->and($order->executedQty)->toEqual(0.001);
});

it('falls back to the live quote when the re-fetch also cannot resolve a price', function () {
    Http::fake(function ($request) {
        if ($request->method() === 'POST' && str_contains($request->url(), '/api/v3/order')) {
            return Http::response([
                'orderId' => 556,
                'status' => 'FILLED',
                'executedQty' => '0.001',
                'price' => '0.00',
                'cummulativeQuoteQty' => '0.00',
                'fills' => [],
            ]);
        }

        if ($request->method() === 'GET' && str_contains($request->url(), '/api/v3/order')) {
            // Simulate Binance's transient -2013 "order does not exist".
            return Http::response(['code' => -2013, 'msg' => 'Order does not exist.'], 400);
        }

        if (str_contains($request->url(), '/api/v3/ticker/price')) {
            return Http::response(['price' => '70000.00']);
        }

        return Http::response([], 404);
    });

    $client = binanceSpotTestClient();
    $order = $client->placeMarketOrder('BTCUSDT', 'BUY', 0.001, 'test-client-id-2');

    expect($order->price)->toEqual(70000.0);
});
