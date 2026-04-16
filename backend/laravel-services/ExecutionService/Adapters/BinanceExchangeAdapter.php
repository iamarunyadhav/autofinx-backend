<?php

namespace Services\ExecutionService\Adapters;

use Illuminate\Support\Facades\Http;

class BinanceExchangeAdapter implements ExchangeAdapter
{
    public function __construct(
        protected string $apiKey,
        protected string $apiSecret,
        protected bool $isTestnet = false,
    ) {}

    protected function baseUrl(): string
    {
        return $this->isTestnet
            ? 'https://testnet.binance.vision'
            : 'https://api.binance.com';
    }

    public function fetchTicker(string $symbol): array
    {
        $res = Http::get($this->baseUrl() . '/api/v3/ticker/24hr', [
            'symbol' => strtoupper($symbol),
        ]);

        if ($res->failed()) {
            throw new \RuntimeException('Binance ticker failed: ' . $res->body());
        }

        $data = $res->json();
        return [
            'symbol' => $data['symbol'] ?? $symbol,
            'price' => (float) ($data['lastPrice'] ?? 0),
            'change24h' => (float) ($data['priceChange'] ?? 0),
            'change24hPercent' => (float) ($data['priceChangePercent'] ?? 0),
        ];
    }

    public function fetchCandles(string $symbol, string $interval = '1m', int $limit = 50): array
    {
        $res = Http::get($this->baseUrl() . '/api/v3/klines', [
            'symbol' => strtoupper($symbol),
            'interval' => $interval,
            'limit' => $limit,
        ]);

        if ($res->failed()) {
            throw new \RuntimeException('Binance candles failed: ' . $res->body());
        }

        return collect($res->json())->map(function ($candle) {
            return [
                'open_time' => $candle[0],
                'open' => (float) $candle[1],
                'high' => (float) $candle[2],
                'low' => (float) $candle[3],
                'close' => (float) $candle[4],
                'volume' => (float) $candle[5],
            ];
        })->all();
    }

    public function placeOrder(array $params): array
    {
        $endpoint = '/api/v3/order';
        $timestamp = (int) (microtime(true) * 1000);
        $payload = array_merge([
            'symbol' => strtoupper($params['symbol']),
            'side' => strtoupper($params['side']),
            'type' => strtoupper($params['type'] ?? 'MARKET'),
            'quantity' => $params['quantity'],
            'timestamp' => $timestamp,
            'newOrderRespType' => 'RESULT',
        ], isset($params['price']) ? ['price' => $params['price']] : []);

        $query = http_build_query($payload);
        $signature = hash_hmac('sha256', $query, $this->apiSecret);
        $url = $this->baseUrl() . $endpoint . '?' . $query . '&signature=' . $signature;

        $res = Http::withHeaders([
            'X-MBX-APIKEY' => $this->apiKey,
        ])->post($url);

        if ($res->failed()) {
            throw new \RuntimeException('Binance place order failed: ' . $res->body());
        }

        return $res->json();
    }

    public function cancelOrder(string $orderId, ?string $symbol = null): array
    {
        if (!$symbol) {
            throw new \InvalidArgumentException('Symbol required for cancel');
        }

        $endpoint = '/api/v3/order';
        $timestamp = (int) (microtime(true) * 1000);
        $payload = [
            'symbol' => strtoupper($symbol),
            'orderId' => $orderId,
            'timestamp' => $timestamp,
        ];

        $query = http_build_query($payload);
        $signature = hash_hmac('sha256', $query, $this->apiSecret);
        $url = $this->baseUrl() . $endpoint . '?' . $query . '&signature=' . $signature;

        $res = Http::withHeaders([
            'X-MBX-APIKEY' => $this->apiKey,
        ])->delete($url);

        if ($res->failed()) {
            throw new \RuntimeException('Binance cancel failed: ' . $res->body());
        }

        return $res->json();
    }

    public function fetchOrderStatus(string $orderId, ?string $symbol = null): string
    {
        if (! $symbol) {
            throw new \InvalidArgumentException('Symbol required for status');
        }

        $timestamp = (int) (microtime(true) * 1000);
        $payload = [
            'symbol' => strtoupper($symbol),
            'orderId' => $orderId,
            'timestamp' => $timestamp,
        ];

        $query = http_build_query($payload);
        $signature = hash_hmac('sha256', $query, $this->apiSecret);
        $url = $this->baseUrl() . '/api/v3/order?' . $query . '&signature=' . $signature;

        $res = Http::withHeaders([
            'X-MBX-APIKEY' => $this->apiKey,
        ])->get($url);

        if ($res->failed()) {
            throw new \RuntimeException('Binance fetchOrderStatus failed: ' . $res->body());
        }

        $status = strtolower($res->json()['status'] ?? '');
        return match ($status) {
            'filled' => 'filled',
            'canceled', 'rejected', 'expired' => 'failed',
            default => 'pending',
        };
    }
}
