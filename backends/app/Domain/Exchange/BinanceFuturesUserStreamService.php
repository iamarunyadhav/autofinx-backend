<?php

namespace App\Domain\Exchange;

use App\Domain\Integrations\IntegrationManager;
use App\Domain\Ops\OperationalBreakerService;
use App\Domain\Trading\FuturesConfigService;
use App\Models\Integration;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class BinanceFuturesUserStreamService
{
    public function __construct(
        private readonly FuturesConfigService $configs,
        private readonly IntegrationManager $integrations,
        private readonly OperationalBreakerService $breakers,
    ) {
    }

    public function start(Integration $integration): array
    {
        $workspaceId = (string) $integration->workspace_id;
        $client = $this->client($integration);
        $listenKey = $client->createListenKey();

        Cache::put($this->cacheKey($integration->id), $listenKey, now()->addMinutes(55));
        $this->breakers->clearProviderOutage($workspaceId, 'binance', 'futures_user_stream_started', 'futures_user_stream');

        return [
            'listen_key' => $listenKey,
            'ws_url' => $this->wsUrl($workspaceId, $listenKey),
            'expires_in_sec' => 60 * 60,
        ];
    }

    public function keepAlive(Integration $integration): ?string
    {
        $listenKey = Cache::get($this->cacheKey($integration->id));
        if (! $listenKey) {
            return null;
        }

        $this->client($integration)->keepAliveListenKey((string) $listenKey);
        Cache::put($this->cacheKey($integration->id), $listenKey, now()->addMinutes(55));
        $this->breakers->clearProviderOutage((string) $integration->workspace_id, 'binance', 'futures_keepalive_ok', 'futures_user_stream');

        return (string) $listenKey;
    }

    public function getOrStart(Integration $integration): array
    {
        $listenKey = Cache::get($this->cacheKey($integration->id));
        if ($listenKey) {
            return [
                'listen_key' => (string) $listenKey,
                'ws_url' => $this->wsUrl((string) $integration->workspace_id, (string) $listenKey),
                'expires_in_sec' => 60 * 60,
            ];
        }

        return $this->start($integration);
    }

    public function stop(Integration $integration): void
    {
        $listenKey = Cache::get($this->cacheKey($integration->id));
        if ($listenKey) {
            $this->client($integration)->closeListenKey((string) $listenKey);
        }

        Cache::forget($this->cacheKey($integration->id));
    }

    public function markFrameActivity(Integration $integration): void
    {
        $this->breakers->clearProviderOutage((string) $integration->workspace_id, 'binance', 'futures_stream_event_received', 'futures_user_stream');
    }

    public function markError(Integration $integration, string $message): void
    {
        $this->breakers->activateProviderOutage((string) $integration->workspace_id, 'binance', $message, 'futures_user_stream');
    }

    private function client(Integration $integration): BinanceFuturesClient
    {
        $config = $this->configs->effective((string) $integration->workspace_id);
        $credentials = $this->integrations->decryptSecrets($integration->secrets_encrypted);
        if ($credentials === []) {
            throw new RuntimeException('Binance futures credentials are missing.');
        }

        return new BinanceFuturesClient(
            apiKey: (string) ($credentials['api_key'] ?? $credentials['key'] ?? ''),
            apiSecret: (string) ($credentials['api_secret'] ?? $credentials['secret'] ?? ''),
            baseUrl: (string) (($config['futures_force_testnet'] ?? true)
                ? config('services.binance.futures_testnet_api_url', 'https://testnet.binancefuture.com')
                : config('services.binance.futures_api_url', 'https://fapi.binance.com')),
        );
    }

    private function wsUrl(string $workspaceId, string $listenKey): string
    {
        $config = $this->configs->effective($workspaceId);
        $baseUrl = (string) (($config['futures_force_testnet'] ?? true)
            ? config('services.binance.futures_testnet_ws_url', 'wss://stream.binancefuture.com')
            : config('services.binance.futures_ws_url', 'wss://fstream.binance.com'));

        return rtrim($baseUrl, '/') . '/ws/' . $listenKey;
    }

    private function cacheKey(string $integrationId): string
    {
        return "binance:futures-user-stream:{$integrationId}:listen-key";
    }
}
