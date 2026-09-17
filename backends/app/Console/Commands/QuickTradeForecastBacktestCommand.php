<?php

namespace App\Console\Commands;

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Models\QuickTrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Periodic calibration refresh for Quick Trade (Unified Forecast Engine
 * plan, Phase 4c). Keeps the RatioBotForecastBacktest rows
 * UnifiedForecastEngine::evaluate() reads (read-only, in its Step 3) from
 * ever going stale, WITHOUT making evaluate() itself do expensive backtest
 * computation inline on every call -- exactly the same
 * "periodic job writes, live call site only reads" split Ratio Bot's own
 * `ratio-bots:forecast-backtest` command already established for
 * runForConfig().
 *
 * Quick Trade only, per this task's scope -- Robot Trade/Future
 * Trade/Fast2X wiring into runForBot() is a separate, later task.
 *
 * bot_instance_id is a stable "{exchange}:{symbol}:{timeframe}" key (plan
 * correction #4: Quick Trade has no natural single config-row identity the
 * way Ratio Bot does, so a stable per-symbol/timeframe identifier is used
 * instead -- never null/empty).
 */
class QuickTradeForecastBacktestCommand extends Command
{
    protected $signature = 'quick-trade:forecast-backtest
        {--limit=50 : Maximum distinct symbol/timeframe/horizon combinations to backtest}';

    protected $description = 'Walk-forward calibration refresh of the Unified Forecast Engine for active Quick Trade symbol/timeframe/horizon combinations (shadow-mode data only -- does not affect any live decision).';

    public function handle(ForecastHorizonBacktestService $service): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $exchange = (string) config('unified_forecast.quick_trade_default_exchange', 'binance');

        // Recently-active symbol/timeframe/expiry combinations -- Quick
        // Trade has no single per-bot config row the way Ratio Bot does, so
        // "active" here means "actually traded recently", not "configured".
        $combinations = QuickTrade::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('symbol, timeframe, expiry_seconds, COUNT(*) as trade_count')
            ->groupBy('symbol', 'timeframe', 'expiry_seconds')
            ->orderByDesc('trade_count')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($combinations as $combination) {
            $symbol = (string) $combination->symbol;
            $timeframe = (string) $combination->timeframe;
            $horizonSeconds = (int) $combination->expiry_seconds;
            $botInstanceId = sprintf('%s:%s:%s', $exchange, $symbol, $timeframe);

            try {
                $service->runForBot('quick_trade', $botInstanceId, $exchange, $symbol, $timeframe, $horizonSeconds);
                $processed++;
            } catch (Throwable $e) {
                Log::warning('[QuickTradeForecastBacktest] Failed for combination', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'horizon_seconds' => $horizonSeconds,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Quick Trade forecast calibration refreshed for {$processed}/{$combinations->count()} symbol/timeframe/horizon combinations.");

        return self::SUCCESS;
    }
}
