<?php

namespace App\Domain\Exchange;

use App\Domain\Ops\OperationalBreakerService;
use App\Models\ExchangeConnection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class BinanceUserStreamService
{
    public function __construct(
        private readonly ExchangeClientFactory $clients,
        private readonly OperationalBreakerService $breakers,
    )
    {
    }

    public function start(ExchangeConnection $connection): array
    {
        $client = $this->clients->makeFromConnection($connection);
        if (! $client instanceof BinanceSpotClient) {
            throw new RuntimeException('User stream is currently supported only for Binance spot.');
        }

        $listenKey = $client->createListenKey();
        if ($listenKey === '') {
            throw new RuntimeException('Failed to initialize Binance user stream listenKey.');
        }

        Cache::put($this->cacheKey($connection->id), $listenKey, now()->addMinutes(55));
        $this->breakers->markStreamStarted($connection, $listenKey);
        $this->breakers->clearProviderOutage((string) $connection->workspace_id, 'binance', 'user_stream_started', 'user_stream');

        return [
            'listen_key' => $listenKey,
            'ws_url' => $client->wsUrlForListenKey($listenKey),
            'expires_in_sec' => 60 * 60,
        ];
    }

    public function keepAlive(ExchangeConnection $connection): ?string
    {
        $listenKey = Cache::get($this->cacheKey($connection->id));
        if (! $listenKey) {
            return null;
        }

        $client = $this->clients->makeFromConnection($connection);
        if (! $client instanceof BinanceSpotClient) {
            return null;
        }

        $client->keepAliveListenKey($listenKey);
        Cache::put($this->cacheKey($connection->id), $listenKey, now()->addMinutes(55));
        $this->breakers->markStreamHeartbeat($connection, 'keepalive');
        $this->breakers->clearProviderOutage((string) $connection->workspace_id, 'binance', 'keepalive_ok', 'user_stream');

        return $listenKey;
    }

    public function getOrStart(ExchangeConnection $connection): array
    {
        $listenKey = Cache::get($this->cacheKey($connection->id));
        if ($listenKey) {
            $client = $this->clients->makeFromConnection($connection);
            if ($client instanceof BinanceSpotClient) {
                return [
                    'listen_key' => $listenKey,
                    'ws_url' => $client->wsUrlForListenKey((string) $listenKey),
                    'expires_in_sec' => 60 * 60,
                ];
            }
        }

        return $this->start($connection);
    }

    public function stop(ExchangeConnection $connection): void
    {
        $listenKey = Cache::get($this->cacheKey($connection->id));
        if (! $listenKey) {
            return;
        }

        $client = $this->clients->makeFromConnection($connection);
        if ($client instanceof BinanceSpotClient) {
            $client->closeListenKey($listenKey);
        }

        Cache::forget($this->cacheKey($connection->id));
        $this->breakers->markStreamStopped($connection);
    }

    public function markFrameActivity(ExchangeConnection $connection, array $context = []): void
    {
        $this->breakers->markStreamEvent($connection, $context);
        $this->breakers->clearProviderOutage((string) $connection->workspace_id, 'binance', 'stream_event_received', 'user_stream');
    }

    public function markError(ExchangeConnection $connection, string $message): void
    {
        $this->breakers->markStreamError($connection, $message);
        $this->breakers->activateProviderOutage((string) $connection->workspace_id, 'binance', $message, 'user_stream');
    }

    private function cacheKey(string $connectionId): string
    {
        return "binance:user-stream:{$connectionId}:listen-key";
    }
}
