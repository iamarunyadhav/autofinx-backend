<?php

namespace App\Domain\FutureTrade;

use App\Domain\Market\FutureTradeLiveFeedManager;
use App\Models\FutureTradeBotConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class FutureTradeRuntimeConnectivityService
{
    public function __construct(
        private readonly FutureTradeLatencyService $latency,
        private readonly FutureTradeUserStreamService $userStream,
    ) {
    }

    public function snapshot(FutureTradeBotConfig $config, FutureTradeLiveFeedManager $liveFeedManager): array
    {
        $market = $liveFeedManager->status((string) $config->workspace_id);
        $heartbeat = $this->marketHeartbeat();
        $userStream = $this->userStream->snapshot($config);
        $latency = $this->latency->summary((string) $config->workspace_id);
        $settings = $config->mergedSettings();
        $requestedTransport = strtolower((string) ($settings['orderTransport'] ?? config('future_trade.order_router.transport', 'rest')));
        $websocketEnabled = array_key_exists('orderWebSocketEnabled', $settings)
            ? (bool) $settings['orderWebSocketEnabled']
            : (bool) config('future_trade.order_router.websocket_enabled', false);
        $effectiveTransport = $requestedTransport === 'websocket' && ! $websocketEnabled
            ? 'rest'
            : $requestedTransport;

        return [
            'market_stream' => [
                'state' => ($market['feedFresh'] ?? false) ? 'healthy' : (($market['running'] ?? false) ? 'warning' : 'blocked'),
                'fresh' => (bool) ($market['feedFresh'] ?? false),
                'running' => (bool) ($market['running'] ?? false),
                'mode' => $market['mode'] ?? null,
                'last_tick_at' => $market['lastTickAt'] ?? null,
                'age_seconds' => $market['feedAgeSeconds'] ?? null,
                'message' => $market['message'] ?? null,
                'heartbeat' => $heartbeat,
            ],
            'user_stream' => [
                'state' => ($userStream['fresh'] ?? false) ? 'healthy' : (($userStream['configured'] ?? false) ? 'warning' : 'waiting'),
                ...$userStream,
            ],
            'order_router' => [
                'state' => $effectiveTransport === 'websocket' ? 'healthy' : 'warning',
                'requested_transport' => $requestedTransport,
                'effective_transport' => $effectiveTransport,
                'websocket_enabled' => $websocketEnabled,
                'message' => $effectiveTransport === 'websocket'
                    ? 'Binance order router is configured for websocket transport.'
                    : 'Binance order router is using REST transport with latency tracking; websocket transport is ready as a separate transport switch.',
            ],
            'latency' => $latency,
        ];
    }

    private function marketHeartbeat(): array
    {
        $path = storage_path('framework/autofinx-live-feed-heartbeat.json');
        if (! File::exists($path)) {
            return [
                'state' => 'waiting',
                'path' => $path,
                'message' => 'No market websocket heartbeat file yet.',
            ];
        }

        $decoded = json_decode((string) File::get($path), true);
        if (! is_array($decoded)) {
            return [
                'state' => 'warning',
                'path' => $path,
                'message' => 'Market websocket heartbeat file is not valid JSON.',
            ];
        }

        $lastEpoch = (int) ($decoded['last_message_epoch'] ?? 0);
        $ageSeconds = $lastEpoch > 0 ? max(0, time() - $lastEpoch) : null;

        return [
            'state' => $ageSeconds !== null && $ageSeconds <= 20 ? 'healthy' : 'warning',
            'path' => $path,
            'last_message_at' => $decoded['last_message_at'] ?? null,
            'age_seconds' => $ageSeconds,
            'symbol' => $decoded['symbol'] ?? null,
            'stream' => $decoded['stream'] ?? null,
            'reconnect_count' => (int) ($decoded['reconnect_count'] ?? 0),
            'message_count' => (int) ($decoded['message_count'] ?? 0),
            'connected_at' => $decoded['connected_at'] ?? null,
            'connected_seconds' => isset($decoded['connected_at'])
                ? max(0, Carbon::parse((string) $decoded['connected_at'])->diffInSeconds(now()))
                : null,
        ];
    }
}
