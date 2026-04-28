<?php

namespace App\Console\Commands;

use App\Domain\Trading\BinanceOrderSyncService;
use App\Domain\Trading\FuturesConfigService;
use App\Domain\Trading\FuturesPositionSyncService;
use App\Models\Integration;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RatioBotReconciliationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RatioBotsReconcileCommand extends Command
{
    protected $signature = 'ratio-bots:reconcile
        {--bot= : Reconcile one ratio bot config id}
        {--limit=50 : Maximum bots to reconcile}';

    protected $description = 'Reconcile ratio bot open orders, positions, and exchange state.';

    public function handle(
        BinanceOrderSyncService $spotSync,
        FuturesPositionSyncService $futuresSync,
        FuturesConfigService $futuresConfigs,
    ): int {
        $botId = trim((string) $this->option('bot'));
        $limit = max(1, min(500, (int) $this->option('limit')));

        $configs = RatioBotConfig::query()
            ->when($botId !== '', fn ($query) => $query->whereKey($botId))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        foreach ($configs as $config) {
            try {
                $snapshot = $config->marketType() === 'futures'
                    ? $this->reconcileFutures($config, $futuresSync, $futuresConfigs)
                    : $this->reconcileSpot($config, $spotSync);

                RatioBotReconciliationLog::create([
                    'id' => (string) Str::uuid(),
                    'ratio_bot_config_id' => $config->id,
                    'market_type' => $config->marketType(),
                    'status' => 'ok',
                    'detail' => 'reconciliation_completed',
                    'snapshot' => $snapshot,
                    'occurred_at' => now(),
                ]);

                RatioBotEvent::create([
                    'ratio_bot_config_id' => $config->id,
                    'event_type' => RatioBotEvent::EVENT_RECONCILIATION_RUN,
                    'context' => $snapshot,
                    'occurred_at' => now(),
                ]);
            } catch (\Throwable $e) {
                RatioBotReconciliationLog::create([
                    'id' => (string) Str::uuid(),
                    'ratio_bot_config_id' => $config->id,
                    'market_type' => $config->marketType(),
                    'status' => 'failed',
                    'detail' => $e->getMessage(),
                    'snapshot' => ['symbol' => $config->normalizedSymbol()],
                    'occurred_at' => now(),
                ]);

                $this->warn(sprintf('Reconcile failed for %s: %s', $config->normalizedSymbol(), $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }

    private function reconcileSpot(RatioBotConfig $config, BinanceOrderSyncService $spotSync): array
    {
        $synced = $spotSync->syncForWorkspace((string) $config->workspace_id, $config->normalizedSymbol(), null, 25);

        return [
            'symbol' => $config->normalizedSymbol(),
            'orders_synced' => $synced,
            'market_type' => 'spot',
        ];
    }

    private function reconcileFutures(
        RatioBotConfig $config,
        FuturesPositionSyncService $futuresSync,
        FuturesConfigService $futuresConfigs,
    ): array {
        $query = Integration::query()
            ->where('workspace_id', $config->workspace_id)
            ->where('provider_key', 'binance')
            ->where('status', 'active');

        if ($config->integration_id) {
            $query->whereKey($config->integration_id);
        }

        $integration = $query->latest('updated_at')->firstOrFail();
        $result = $futuresSync->sync((string) $config->workspace_id, $integration, $futuresConfigs->effective((string) $config->workspace_id));

        return [
            'symbol' => $config->normalizedSymbol(),
            'market_type' => 'futures',
            'sync' => $result,
        ];
    }
}
