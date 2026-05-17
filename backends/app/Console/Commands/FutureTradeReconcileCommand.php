<?php

namespace App\Console\Commands;

use App\Domain\Exchange\BinanceFuturesClient;
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

    public function handle(FutureTradeReconciliationService $reconciler): int
    {
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
                    $settings = $config->mergedSettings();
                    $forceTestnet = (bool) ($settings['forceTestnet'] ?? true);
                    $apiKey = (string) ($forceTestnet ? ($settings['testnetApiKey'] ?? '') : ($settings['liveApiKey'] ?? ''));
                    $apiSecret = (string) ($forceTestnet ? ($settings['testnetApiSecret'] ?? '') : ($settings['liveApiSecret'] ?? ''));
                    if ($apiKey === '' || $apiSecret === '') {
                        $this->warn('Skipping ' . $config->workspace_id . ' — no API credentials');
                        continue;
                    }
                    $baseUrl = (string) ($forceTestnet
                        ? ($settings['testnetApiUrl'] ?? config('services.binance.futures_testnet_url') ?? 'https://testnet.binancefuture.com')
                        : ($settings['liveApiUrl'] ?? config('services.binance.futures_url') ?? 'https://fapi.binance.com'));

                    $client = new BinanceFuturesClient($baseUrl, $apiKey, $apiSecret);
                    $report = $reconciler->reconcileConfig($config, $client, $symbol);
                    Log::info('[FT RECONCILE REPORT]', $report);
                    $this->line(sprintf(
                        '%s: closed=%d corrected=%d flagged=%d errors=%d',
                        $config->workspace_id,
                        $report['closed'],
                        $report['corrected'],
                        $report['flagged'],
                        count($report['errors'])
                    ));
                } catch (Throwable $e) {
                    $this->error('[FT RECONCILE ERROR] ' . $config->workspace_id . ': ' . $e->getMessage());
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
