<?php

namespace App\Console\Commands;

use App\Domain\FutureTrade\FutureTradeFuturesCredentialResolver;
use App\Domain\FutureTrade\FutureTradeReconciliationService;
use App\Models\FutureTradeBotConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class FutureTradeReconcileCommand extends Command
{
    protected $signature = 'future-trade:reconcile
        {--workspace= : Limit to a single workspace}
        {--symbol= : Limit to a single symbol}';

    protected $description = 'Reconcile local logical trades against Binance Futures REST state (positions + recent fills).';

    public function handle(
        FutureTradeReconciliationService $reconciler,
        FutureTradeFuturesCredentialResolver $credentials,
    ): int {
        $lock = Cache::lock('autofinx_ft:reconcile_lock', 600);
        if (! $lock->get()) {
            $this->warn('[FT RECONCILE SKIPPED] another reconciliation is already running');

            return self::SUCCESS;
        }

        try {
            $workspaceId = trim((string) $this->option('workspace'));
            $symbol = trim((string) $this->option('symbol')) ?: null;

            $configs = FutureTradeBotConfig::query()
                ->when($workspaceId !== '', fn ($q) => $q->where('workspace_id', $workspaceId))
                ->where('enable_live_trading', true)
                ->get();

            if ($configs->isEmpty()) {
                $this->info('No live-enabled future-trade configs to reconcile.');

                return self::SUCCESS;
            }

            foreach ($configs as $config) {
                try {
                    $client = $credentials->clientForConfig($config, 'reconcile');
                    if (! $client) {
                        $resolved = $credentials->resolveForConfig($config);
                        $this->warn('Skipping '.$config->workspace_id.' - no API credentials: '.($resolved['reason'] ?? 'not_configured'));

                        continue;
                    }

                    $report = $reconciler->reconcileConfig($config, $client, $symbol);
                    Log::info('[FT RECONCILE REPORT]', $report);
                    $this->line(sprintf(
                        '%s: closed=%d corrected=%d flagged=%d legacy_closed=%d state_cleared=%d errors=%d',
                        $config->workspace_id,
                        $report['closed'],
                        $report['corrected'],
                        $report['flagged'],
                        $report['legacy_closed'] ?? 0,
                        $report['state_cleared'] ?? 0,
                        count($report['errors'])
                    ));
                } catch (Throwable $e) {
                    $this->error('[FT RECONCILE ERROR] '.$config->workspace_id.': '.$e->getMessage());
                    Log::warning('[FT RECONCILE ERROR]', [
                        'workspace_id' => $config->workspace_id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
