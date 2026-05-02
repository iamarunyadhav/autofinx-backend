<?php

namespace App\Domain\Fast2X;

use App\Support\WebSocket\SimpleWebSocketClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BinanceSpotProvider implements ExchangeProviderInterface
{
    private ?SimpleWebSocketClient $client = null;
    private $callback = null;
    private bool $connected = false;
    private string $feedMode = 'websocket';
    private ?string $lastConnectionError = null;

    public function connectWebSocket(string $symbol): void
    {
        $stream = strtolower($symbol) . '@ticker';
        $base = rtrim((string) config('services.binance.ws_url', env('BINANCE_WS_URL', 'wss://stream.binance.com:9443')), '/');
        $this->client = new SimpleWebSocketClient();
        $this->client->connect($base . '/ws/' . $stream);
        $this->connected = true;
        $this->feedMode = 'websocket';
        $this->lastConnectionError = null;
    }

    public function disconnectWebSocket(): void
    {
        $this->client?->close();
        $this->client = null;
        $this->connected = false;
    }

    public function onPriceUpdate(callable $callback): void
    {
        $this->callback = $callback;
    }

    public function readNextTick(string $symbol, int $timeoutSeconds = 2): ?array
    {
        if (! $this->client) {
            return null;
        }

        $frame = $this->client->readFrame($timeoutSeconds);
        if (($frame['type'] ?? null) !== 'text') {
            return null;
        }

        $payload = json_decode((string) $frame['payload'], true);
        if (! is_array($payload)) {
            return null;
        }

        $price = (float) ($payload['c'] ?? $payload['p'] ?? 0);
        if ($price <= 0) {
            return null;
        }

        $tick = Types::priceTick($symbol, $price, [
            'bid' => isset($payload['b']) ? (float) $payload['b'] : null,
            'ask' => isset($payload['a']) ? (float) $payload['a'] : null,
            'volume' => isset($payload['v']) ? (float) $payload['v'] : null,
            'eventTime' => (int) ($payload['E'] ?? floor(microtime(true) * 1000)),
        ]);

        if ($this->callback) {
            ($this->callback)($tick);
        }

        return $tick;
    }

    public function usePollingFallback(?string $reason = null): void
    {
        $this->disconnectWebSocket();
        $this->feedMode = 'polling';
        $this->lastConnectionError = $reason;
    }

    public function isWebSocketConnected(): bool
    {
        return $this->connected && $this->client !== null;
    }

    public function feedMode(): string
    {
        return $this->feedMode;
    }

    public function lastConnectionError(): ?string
    {
        return $this->lastConnectionError;
    }

    public function latestTicker(string $symbol): array
    {
        $base = rtrim((string) config('services.binance.api_url', env('BINANCE_API_URL', 'https://api.binance.com')), '/');
        $started = microtime(true);
        $ticker = Http::timeout(5)->get($base . '/api/v3/ticker/24hr', ['symbol' => strtoupper($symbol)]);
        if (! $ticker->successful()) {
            throw new RuntimeException('Binance ticker unavailable.');
        }

        $book = Http::timeout(5)->get($base . '/api/v3/ticker/bookTicker', ['symbol' => strtoupper($symbol)]);
        $bookData = $book->successful() ? $book->json() : [];
        $data = $ticker->json();
        $price = (float) ($data['lastPrice'] ?? 0);

        return [
            'tick' => Types::priceTick($symbol, $price, [
                'bid' => $bookData['bidPrice'] ?? null,
                'ask' => $bookData['askPrice'] ?? null,
                'volume' => $data['volume'] ?? null,
                'eventTime' => (int) ($data['closeTime'] ?? floor(microtime(true) * 1000)),
            ]),
            'change24h' => (float) ($data['priceChangePercent'] ?? 0),
            'latencyMs' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    public function placeMarketBuy(string $symbol, float $amount): array
    {
        return $this->blockedLiveOrder('BUY', $symbol, $amount);
    }

    public function placeMarketSell(string $symbol, float $quantity): array
    {
        return $this->blockedLiveOrder('SELL', $symbol, $quantity);
    }

    public function getBalance(string $asset): array
    {
        return ['asset' => strtoupper($asset), 'free' => 0, 'locked' => 0, 'mode' => 'UNIMPLEMENTED_LIVE'];
    }

    public function getOrderStatus(string $orderId): array
    {
        return ['orderId' => $orderId, 'status' => 'UNKNOWN'];
    }

    public function checkExchangeHealth(): array
    {
        try {
            $base = rtrim((string) config('services.binance.api_url', env('BINANCE_API_URL', 'https://api.binance.com')), '/');
            $response = Http::timeout(5)->get($base . '/api/v3/ping');
            return ['healthy' => $response->successful(), 'status' => $response->successful() ? 'OK' : 'ERROR'];
        } catch (Throwable $e) {
            return ['healthy' => false, 'status' => 'ERROR', 'reason' => $e->getMessage()];
        }
    }

    public function measureLatency(): int
    {
        $started = microtime(true);
        $this->checkExchangeHealth();
        return (int) round((microtime(true) - $started) * 1000);
    }

    private function blockedLiveOrder(string $side, string $symbol, float $amount): array
    {
        if (! (bool) config('fast2x.enable_live_trading')) {
            throw new RuntimeException('Live trading is disabled. Fast2X defaults to paper trading.');
        }

        return [
            'orderId' => 'live-not-implemented',
            'side' => $side,
            'symbol' => strtoupper($symbol),
            'executedQty' => $amount,
            'status' => 'REJECTED',
        ];
    }
}
