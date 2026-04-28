<?php

namespace App\Jobs;

use App\Domain\Exchange\BinanceUserStreamService;
use App\Domain\Exchange\BinanceFuturesUserStreamService;
use App\Domain\Trading\BinanceOrderSyncService;
use App\Domain\Trading\FuturesConfigService;
use App\Domain\Trading\FuturesPositionSyncService;
use App\Models\Bot;
use App\Models\ExchangeConnection;
use App\Models\Integration;
use App\Models\RatioBotConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class KeepAliveUserStreamsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('reconcile');
    }

    public function handle(
        BinanceUserStreamService $streams,
        BinanceOrderSyncService $sync,
        BinanceFuturesUserStreamService $futuresStreams,
        FuturesPositionSyncService $futuresSync,
        FuturesConfigService $futuresConfigs,
    ): void
    {
        $connectionIds = Bot::where('status', 'RUNNING')
            ->where('is_live', true)
            ->whereNotNull('connection_id')
            ->distinct()
            ->pluck('connection_id');

        foreach ($connectionIds as $connectionId) {
            $connection = ExchangeConnection::find($connectionId);
            if (! $connection || ! $connection->is_active) {
                continue;
            }

            try {
                $listenKey = $streams->keepAlive($connection);
                if (! $listenKey) {
                    $streams->start($connection);
                    $sync->syncForConnection($connection);
                }
            } catch (\Throwable $e) {
                $streams->markError($connection, $e->getMessage());

                try {
                    $streams->start($connection);
                    $sync->syncForConnection($connection);
                } catch (\Throwable $restartError) {
                    $streams->markError($connection, $restartError->getMessage());
                }
            }
        }

        $integrationIds = RatioBotConfig::query()
            ->where('enabled', true)
            ->where('market_type', 'futures')
            ->whereNotNull('integration_id')
            ->distinct()
            ->pluck('integration_id');

        foreach ($integrationIds as $integrationId) {
            $integration = Integration::find($integrationId);
            if (! $integration || strtolower((string) $integration->status) !== 'active') {
                continue;
            }

            try {
                $listenKey = $futuresStreams->keepAlive($integration);
                if (! $listenKey) {
                    $futuresStreams->start($integration);
                    $futuresSync->sync((string) $integration->workspace_id, $integration, $futuresConfigs->effective((string) $integration->workspace_id));
                }
            } catch (\Throwable $e) {
                $futuresStreams->markError($integration, $e->getMessage());

                try {
                    $futuresStreams->start($integration);
                    $futuresSync->sync((string) $integration->workspace_id, $integration, $futuresConfigs->effective((string) $integration->workspace_id));
                } catch (\Throwable $restartError) {
                    $futuresStreams->markError($integration, $restartError->getMessage());
                }
            }
        }
    }
}
