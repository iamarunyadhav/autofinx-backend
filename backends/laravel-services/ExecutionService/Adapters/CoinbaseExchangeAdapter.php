<?php

namespace Services\ExecutionService\Adapters;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CoinbaseExchangeAdapter implements ExchangeAdapter
{
    public function __construct(
        protected string $apiKey,
        protected string $apiSecret,
        protected bool $isSandbox = false,
    ) {}

    protected function baseUrl(): string
    {
        return $this->isSandbox
            ? 'https://api-public.sandbox.exchange.coinbase.com'
            : 'https://api.exchange.coinbase.com';
    }

    public function fetchTicker(string $symbol): array
    {
        $res = Http::get($this->baseUrl() . '/products/' . strtoupper($symbol) . '/ticker');
        if ($res->failed()) {
            throw new \RuntimeException('Coinbase ticker failed: ' . $res->body());
        }
        $data = $res->json();
        return [
            'symbol' => strtoupper($symbol),
            'price' => (float) ($data['price'] ?? 0),
            'change24h' => 0,
            'change24hPercent' => 0,
        ];
    }

    public function fetchCandles(string $symbol, string $interval = '1m', int $limit = 50): array
    {
        $granularity = match ($interval) {
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '1h' => 3600,
            default => 60,
        };
        $res = Http::get($this->baseUrl() . '/products/' . strtoupper($symbol) . '/candles', [
            'granularity' => $granularity,
            'limit' => $limit,
        ]);
        if ($res->failed()) {
            throw new \RuntimeException('Coinbase candles failed: ' . $res->body());
        }

        return collect($res->json())->map(function ($candle) {
            return [
                'open_time' => $candle[0] * 1000,
                'low' => (float) $candle[1],
                'high' => (float) $candle[2],
                'open' => (float) $candle[3],
                'close' => (float) $candle[4],
                'volume' => (float) $candle[5],
            ];
        })->all();
    }

    public function placeOrder(array $params): array
    {
        $timestamp = time();
        $method = 'POST';
        $requestPath = '/api/v3/brokerage/orders';

        $body = [
            'client_order_id' => $params['idempotency_key'] ?? 'co_' . \Illuminate\Support\Str::random(12),
            'product_id' => strtoupper($params['symbol']),
            'side' => strtolower($params['side']),
            'order_configuration' => [
                'market_market_ioc' => [
                    'quote_size' => (string) $params['quantity'],
                ],
            ],
        ];

        $signature = $this->sign($timestamp, $method, $requestPath, $body);

        $res = Http::withHeaders([
            'CB-ACCESS-KEY' => $this->apiKey,
            'CB-ACCESS-SIGN' => $signature,
            'CB-ACCESS-TIMESTAMP' => $timestamp,
            'CB-VERSION' => '2023-12-01',
        ])->post($this->baseUrl() . $requestPath, $body);

        if ($res->failed()) {
            Log::error('Coinbase place order failed', ['body' => $res->body()]);
            throw new \RuntimeException('Coinbase place order failed: ' . $res->body());
        }

        return $res->json();
    }

    public function cancelOrder(string $orderId, ?string $symbol = null): array
    {
        $timestamp = time();
        $method = 'POST';
        $requestPath = '/api/v3/brokerage/orders/batch_cancel';
        $body = [
            'order_ids' => [$orderId],
        ];

        $signature = $this->sign($timestamp, $method, $requestPath, $body);

        $res = Http::withHeaders([
            'CB-ACCESS-KEY' => $this->apiKey,
            'CB-ACCESS-SIGN' => $signature,
            'CB-ACCESS-TIMESTAMP' => $timestamp,
            'CB-VERSION' => '2023-12-01',
        ])->post($this->baseUrl() . $requestPath, $body);

        if ($res->failed()) {
            throw new \RuntimeException('Coinbase cancelOrder failed: ' . $res->body());
        }

        return $res->json();
    }

    public function fetchOrderStatus(string $orderId, ?string $symbol = null): string
    {
        $timestamp = time();
        $method = 'GET';
        $requestPath = '/api/v3/brokerage/orders/historical/' . $orderId;
        $body = [];

        $signature = $this->sign($timestamp, $method, $requestPath, $body);

        $res = Http::withHeaders([
            'CB-ACCESS-KEY' => $this->apiKey,
            'CB-ACCESS-SIGN' => $signature,
            'CB-ACCESS-TIMESTAMP' => $timestamp,
            'CB-VERSION' => '2023-12-01',
        ])->get($this->baseUrl() . $requestPath);

        if ($res->failed()) {
            throw new \RuntimeException('Coinbase fetchOrderStatus failed: ' . $res->body());
        }

        $status = strtolower($res->json()['order']['status'] ?? '');
        return match ($status) {
            'filled', 'filled_fully', 'done' => 'filled',
            'rejected', 'failed', 'canceled' => 'failed',
            default => 'pending',
        };
    }

    private function sign(int $timestamp, string $method, string $path, array $body): string
    {
        $jsonBody = json_encode($body);
        $prehash = $timestamp . $method . $path . $jsonBody;
        return hash_hmac('sha256', $prehash, $this->apiSecret);
    }
}
