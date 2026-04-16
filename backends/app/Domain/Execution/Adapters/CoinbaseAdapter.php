<?php

namespace App\Domain\Execution\Adapters;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CoinbaseAdapter implements ExchangeAdapterInterface
{
    private function ensureEnabled(): void
    {
        if (!env('LIVE_TRADING_ENABLED', false) || !env('COINBASE_ENABLED', false)) {
            throw new RuntimeException('Coinbase live trading disabled');
        }
    }

    public function testConnection(array $credentials): array
    {
        $base = $credentials['base_url'] ?? env('COINBASE_BASE_URL', 'https://api.coinbase.com');
        $response = Http::get("{$base}/v2/time");
        return [
            'ok' => $response->successful(),
            'response' => $response->json(),
        ];
    }

    public function fetchTicker(string $symbol): array
    {
        $base = env('COINBASE_BASE_URL', 'https://api.coinbase.com');
        $response = Http::get("{$base}/v2/prices/{$symbol}/spot");
        if (!$response->successful()) {
            return ['symbol' => $symbol, 'price' => null];
        }
        $json = $response->json();
        return [
            'symbol' => $symbol,
            'price' => $json['data']['amount'] ?? null,
        ];
    }

    public function placeOrder(string $symbol, string $side, float $qty, string $type, string $idempotencyKey): array
    {
        $this->ensureEnabled();
        throw new RuntimeException('Live order placement not enabled in this build');
    }

    public function cancelOrder(string $externalOrderId): array
    {
        $this->ensureEnabled();
        throw new RuntimeException('Live order cancel not enabled in this build');
    }
}
