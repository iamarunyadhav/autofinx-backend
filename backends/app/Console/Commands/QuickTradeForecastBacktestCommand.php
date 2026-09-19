<?php

namespace App\Console\Commands;

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Jobs\RunQuickTradeHistoricalBacktestJob;
use App\Models\BacktestRun;
use Illuminate\Support\Str;
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
    /**
     * If a RunQuickTradeHistoricalBacktestJob's worker process dies mid-run
     * (server restart, OOM kill) it never reaches the finally-equivalent
     * catch block in RunQuickTradeHistoricalBacktestJob::handle() that would
     * mark the row 'failed', so a BacktestRun can otherwise stay
     * 'status=running' forever with a stale started_at. This command
     * already runs daily and is the natural place to sweep those --
     * backtests are read-only analytics (Multi-Window Forecast Engine Part
     * C), not part of any live trading loop, so a daily sweep is an
     * acceptable cadence rather than needing its own schedule entry. 30
     * minutes is well above the job's own 120s timeout and 3-try/backoff
     * ceiling (worst case: 120s timeout x 3 tries + 30s + 120s backoff
     * ~= 8.5 minutes), so a 'running' row older than that is not a slow
     * job -- it is a dead one.
     */
    private const STUCK_RUN_TIMEOUT_MINUTES = 30;

    protected $signature = 'quick-trade:forecast-backtest
        {--limit=50 : Maximum distinct symbol/timeframe/horizon combinations to backtest}
        {--async : Dispatch each backtest to the analytics queue instead of running inline}
        {--candle-limit=500 : Historical candles to backfill per combination}';

    protected $description = 'Walk-forward calibration refresh of the Unified Forecast Engine for active Quick Trade symbol/timeframe/horizon combinations (shadow-mode data only -- does not affect any live decision).';

    public function handle(ForecastHorizonBacktestService $service): int
    {
        $this->recoverStuckRuns();

        $limit = max(1, min(200, (int) $this->option('limit')));
        $candleLimit = max(30, min(1000, (int) $this->option('candle-limit')));
        $async = (bool) $this->option('async');
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
                if ($async) {
                    $run = BacktestRun::create([
                        'id' => (string) Str::uuid(), 'bot_type' => 'quick_trade',
                        'exchange' => $exchange, 'symbol' => $symbol, 'timeframe' => $timeframe,
                        'horizon_seconds' => $horizonSeconds, 'candle_limit' => $candleLimit,
                        'status' => 'queued', 'queued_at' => now(),
                    ]);
                    RunQuickTradeHistoricalBacktestJob::dispatch(
                        $exchange, $symbol, $timeframe, $horizonSeconds, $candleLimit, $run->id
                    )->onQueue('analytics');
                } else {
                    $service->runForBot('quick_trade', $botInstanceId, $exchange, $symbol, $timeframe, $horizonSeconds);
                }
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

    /**
     * Mark any BacktestRun stuck in 'running' past a reasonable timeout as
     * 'failed' so it stops shadowing a genuinely-dead job and so operators
     * see it (Log::error is this codebase's established minimum-viable
     * failure-visibility pattern for scheduled/background work -- see
     * DetectStaleFeedsJob/OpsStaleFeedCheckCommand's equivalent risk-event
     * logging).
     */
    private function recoverStuckRuns(): void
    {
        $stuck = BacktestRun::query()
            ->where('status', 'running')
            ->where('started_at', '<', now()->subMinutes(self::STUCK_RUN_TIMEOUT_MINUTES))
            ->get();

        foreach ($stuck as $run) {
            $run->update([
                'status' => 'failed',
                'error_message' => 'stuck_job_recovered_by_timeout',
                'finished_at' => now(),
            ]);

            Log::error('[QuickTradeForecastBacktest] recovered stuck BacktestRun', [
                'backtest_run_id' => $run->id,
                'bot_type' => $run->bot_type,
                'exchange' => $run->exchange,
                'symbol' => $run->symbol,
                'timeframe' => $run->timeframe,
                'horizon_seconds' => $run->horizon_seconds,
                'started_at' => optional($run->started_at)->toIso8601String(),
            ]);
        }
    }
}
