<?php

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\QuickTradeManual\Exchange\BinanceAlgoApi;
use App\Domain\QuickTradeManual\Exchange\BinanceManualGateway;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use Illuminate\Support\Facades\Http;

/*
 * Pins the (UNVERIFIED-ON-EXCHANGE) algo-order request shapes so any change is deliberate.
 * These tests prove what we SEND; they cannot prove Binance accepts it. See TESTNET_CHECKLIST.md.
 */

function qtmGateway(): BinanceManualGateway
{
    return new BinanceManualGateway(new BinanceFuturesClient('k', 's', 'https://testnet.binancefuture.com', 'qtm_test'), 'testnet');
}

test('algo placement params: CONDITIONAL, closePosition=true, no quantity, no reduceOnly, triggerPrice', function () {
    $p = BinanceAlgoApi::placeParams(['symbol' => 'BTCUSDT', 'side' => 'SELL', 'position_side' => null, 'type' => 'STOP_MARKET', 'trigger_price' => '79200.0', 'client_algo_id' => 'qtm-sl-x-1', 'working_type' => 'MARK_PRICE']);
    expect($p)->toBe([
        'algoType' => 'CONDITIONAL', 'symbol' => 'BTCUSDT', 'side' => 'SELL', 'type' => 'STOP_MARKET',
        'triggerPrice' => '79200.0', 'closePosition' => 'true', 'workingType' => 'MARK_PRICE', 'clientAlgoId' => 'qtm-sl-x-1',
    ])->and($p)->not->toHaveKey('quantity')->and($p)->not->toHaveKey('reduceOnly')->and($p)->not->toHaveKey('stopPrice');
});

test('trailing placement uses callbackRate and optional activationPrice instead of triggerPrice', function () {
    $p = BinanceAlgoApi::placeParams([
        'symbol' => 'BTCUSDT', 'side' => 'SELL', 'position_side' => null,
        'type' => 'TRAILING_STOP_MARKET', 'callback_rate' => '0.4000',
        'activation_price' => '80800.0', 'client_algo_id' => 'qtm-trailing-x-1', 'working_type' => 'MARK_PRICE',
    ]);
    expect($p)->toMatchArray([
        'algoType' => 'CONDITIONAL', 'symbol' => 'BTCUSDT', 'side' => 'SELL', 'type' => 'TRAILING_STOP_MARKET',
        'closePosition' => 'true', 'callbackRate' => '0.4000', 'activationPrice' => '80800.0',
        'workingType' => 'MARK_PRICE', 'clientAlgoId' => 'qtm-trailing-x-1',
    ])->not->toHaveKey('triggerPrice');
});

test('hedge mode adds positionSide to the algo order', function () {
    $p = BinanceAlgoApi::placeParams(['symbol' => 'BTCUSDT', 'side' => 'SELL', 'position_side' => 'LONG', 'type' => 'STOP_MARKET', 'trigger_price' => '1', 'client_algo_id' => 'a', 'working_type' => 'MARK_PRICE']);
    expect($p['positionSide'])->toBe('LONG');
});

test('gateway posts the algo order to /fapi/v1/algoOrder (NOT /fapi/v1/order) and requires an algoId in the answer', function () {
    Http::fake([
        '*/fapi/v1/algoOrder*' => Http::response(['algoId' => 12345, 'clientAlgoId' => 'qtm-sl-x-1', 'algoStatus' => 'NEW'], 200),
    ]);
    $ack = qtmGateway()->placeProtective(['symbol' => 'BTCUSDT', 'side' => 'SELL', 'position_side' => null, 'type' => 'STOP_MARKET', 'trigger_price' => '79200.0', 'client_algo_id' => 'qtm-sl-x-1', 'working_type' => 'MARK_PRICE']);
    expect($ack['algo_id'])->toBe('12345');

    Http::assertSent(function ($request) {
        parse_str((string) $request->body(), $form);

        return $request->method() === 'POST'
            && str_ends_with($request->url(), '/fapi/v1/algoOrder')
            && ($form['algoType'] ?? null) === 'CONDITIONAL'
            && ($form['closePosition'] ?? null) === 'true'
            && ($form['triggerPrice'] ?? null) === '79200.0'
            && ! array_key_exists('quantity', $form)
            && isset($form['signature']);
    });
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/fapi/v1/order'));
});

test('a 2xx answer without an algoId is NOT an acknowledgement', function () {
    Http::fake(['*/fapi/v1/algoOrder*' => Http::response(['code' => 200, 'msg' => 'ok'], 200)]);
    expect(fn () => qtmGateway()->placeProtective(['symbol' => 'BTCUSDT', 'side' => 'SELL', 'position_side' => null, 'type' => 'STOP_MARKET', 'trigger_price' => '1.0', 'client_algo_id' => 'a', 'working_type' => 'MARK_PRICE']))
        ->toThrow(ManualExchangeException::class);
});

test('a definite exchange rejection is non-ambiguous, a 5xx / -1007 is ambiguous', function () {
    Http::fake(['*/fapi/v1/algoOrder*' => Http::sequence()
        ->push(['code' => -4120, 'msg' => 'Order type not supported for this endpoint'], 400)
        ->push(['code' => -1007, 'msg' => 'Timeout waiting for response from backend server. Send status unknown'], 503)]);
    $args = ['symbol' => 'BTCUSDT', 'side' => 'SELL', 'position_side' => null, 'type' => 'STOP_MARKET', 'trigger_price' => '1.0', 'client_algo_id' => 'a', 'working_type' => 'MARK_PRICE'];
    try {
        qtmGateway()->placeProtective($args);
        $this->fail('expected exception');
    } catch (ManualExchangeException $e) {
        expect($e->ambiguous)->toBeFalse()->and($e->exchangeCode)->toBe(-4120);
    }
    try {
        qtmGateway()->placeProtective($args);
        $this->fail('expected exception');
    } catch (ManualExchangeException $e) {
        expect($e->ambiguous)->toBeTrue();
    }
});

test('cancel sends parameters in the query string and treats "already gone" as success', function () {
    Http::fake(['*/fapi/v1/algoOrder*' => Http::response(['code' => -2011, 'msg' => 'Unknown order sent.'], 400)]);
    qtmGateway()->cancelProtective('BTCUSDT', '777', null);
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'algoId=777') && str_contains($r->url(), 'signature='));
});

test('entry and close are MARKET orders; close is reduceOnly in one-way and positionSide-scoped in hedge', function () {
    Http::fake(['*/fapi/v1/order*' => Http::response(['orderId' => 9, 'clientOrderId' => 'c', 'status' => 'FILLED', 'executedQty' => '0.012', 'avgPrice' => '80000.0'], 200)]);
    $g = qtmGateway();
    $g->placeClose(['symbol' => 'BTCUSDT', 'side' => 'SELL', 'quantity' => '0.012', 'position_side' => null, 'client_order_id' => 'c']);
    $g->placeClose(['symbol' => 'BTCUSDT', 'side' => 'SELL', 'quantity' => '0.012', 'position_side' => 'LONG', 'client_order_id' => 'c2']);
    $seen = [];
    Http::assertSent(function ($r) use (&$seen) {
        parse_str((string) $r->body(), $f);
        $seen[] = $f;

        return true;
    });
    expect($seen[0]['reduceOnly'] ?? null)->toBe('true')->and($seen[0])->not->toHaveKey('positionSide')
        ->and($seen[1])->not->toHaveKey('reduceOnly')->and($seen[1]['positionSide'])->toBe('LONG')
        ->and($seen[0]['type'])->toBe('MARKET');
});

test('API secrets never appear in exception messages', function () {
    Http::fake(['*' => Http::response('boom signature=abcdef123 X-MBX-APIKEY: s3cr3t', 500)]);
    try {
        qtmGateway()->queryOrder('BTCUSDT', '1', null);
    } catch (ManualExchangeException $e) {
        expect($e->getMessage())->not->toContain('abcdef123')->not->toContain('s3cr3t');
    }
    expect(true)->toBeTrue();
});
