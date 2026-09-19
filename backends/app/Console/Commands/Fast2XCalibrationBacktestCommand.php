<?php

namespace App\Console\Commands;

use App\Domain\Fast2X\Fast2XCalibrationService;
use App\Models\Fast2XConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Periodic calibration refresh for Fast2X (mirrors
 * RobotTradeForecastBacktestCommand's/QuickTradeForecastBacktestCommand's
 * existing "periodic job writes, live decision path only reads (Fast2X
 * doesn't even read this yet)" split). Uses Fast2XCalibrationService's
 * NATIVE decision-log/trade-based calibration, not
 * ForecastHorizonBacktestService::runForBot() -- Fast2X's real entry/exit
 * decision never calls TradeForecastService::forecast(), so runForBot()'s
 * OHLC-forecast walk-forward would measure something Fast2X never uses.
 *
 * NEVER called from Fast2XService::processTick()/evaluateEntry()/
 * manageOpenPosition() or any other part of the live tick loop -- this
 * command and the service it calls are read-only analytics only.
 */
class Fast2XCalibrationBacktestCommand extends Command
{
    protected $signature = 'fast2x:calibration-backtest
        {--config= : Backtest one Fast2X config id}
        {--limit=200 : Maximum Fast2X configs to backtest}';

    protected $description = 'Native Fast2X calibration refresh (real decision-log trigger -> real fee/slippage-netted trade outcome) for active Fast2X configs -- tracking-only, never affects any live decision.';

    public function handle(Fast2XCalibrationService $service): int
    {
        $configId = trim((string) $this->option('config'));
        $limit = max(1, min(1000, (int) $this->option('limit')));

        $configs = Fast2XConfig::query()
            ->when($configId !== '', fn ($query) => $query->whereKey($configId))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($configs as $config) {
            try {
                $result = $service->calibrate($config);
                if ($result !== null) {
                    $processed++;
                }
            } catch (Throwable $e) {
                Log::warning('[Fast2XCalibrationBacktest] Failed for config', [
                    'fast2x_config_id' => $config->id,
                    'symbol' => $config->symbol,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Fast2X calibration refreshed for {$processed}/{$configs->count()} configs.");

        return self::SUCCESS;
    }
}
