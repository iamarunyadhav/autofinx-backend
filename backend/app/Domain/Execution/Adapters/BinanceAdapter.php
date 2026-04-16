<?php

namespace App\Domain\Execution\Adapters;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class BinanceAdapter implements ExchangeAdapterInterface
{
    private function ensureEnabled(): void
    {
        if (!env('LIVE_TRADING_ENABLED', false) || !env('BINANCE_ENABLED', false)) {
            throw new RuntimeException('Binance live trading disabled');
        }
    }

    public function testConnection(array $credentials): array
    {
        $base = $credentials['base_url'] ?? env('BINANCE_BASE_URL', 'https://api.binance.com');
        $response = Http::get("{$base}/api/v3/time");
        return [
            'ok' => $response->successful(),
            'response' => $response->json(),
        ];
    }

    public function fetchTicker(string $symbol): array
    {
        $base = env('BINANCE_BASE_URL', 'https://api.binance.com');
        $response = Http::get("{$base}/api/v3/ticker/price", ['symbol' => $symbol]);
        if (!$response->successful()) {
            return ['symbol' => $symbol, 'price' => null];
        }
        return $response->json();
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
