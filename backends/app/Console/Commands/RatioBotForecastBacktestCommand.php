<?php

namespace App\Console\Commands;

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Models\RatioBotConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RatioBotForecastBacktestCommand extends Command
{
    protected $signature = 'ratio-bots:forecast-backtest
        {--bot= : Backtest one ratio bot config id}
        {--limit=50 : Maximum bots to backtest}';

    protected $description = 'Walk-forward backtest of the Near-Term Forecast bias per Ratio Bot, honestly measuring hit-rate at each bot\'s effective horizon.';

    public function handle(ForecastHorizonBacktestService $service): int
    {
        $botId = trim((string) $this->option('bot'));
        $limit = max(1, min(500, (int) $this->option('limit')));

        $configs = RatioBotConfig::query()
            ->where('brain_enabled', true)
            ->when($botId === '', fn ($query) => $query->where('enabled', true))
            ->when($botId !== '', fn ($query) => $query->whereKey($botId))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($configs as $config) {
            try {
                if ($service->runForConfig($config)) {
                    $processed++;
                }
            } catch (Throwable $e) {
                Log::warning('[RatioBotForecastBacktest] Failed for bot', [
                    'config_id' => $config->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Forecast backtest computed for {$processed}/{$configs->count()} brain-enabled bots.");

        return self::SUCCESS;
    }
}
