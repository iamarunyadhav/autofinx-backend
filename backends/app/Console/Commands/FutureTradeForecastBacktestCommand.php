<?php

namespace App\Console\Commands;

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Domain\Trading\TimeframeRegistry;
use App\Models\FutureTradeLogicalTrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Periodic calibration refresh for Future Trade (Unified Forecast Engine
 * plan -- Robot Trade/Future Trade/Fast2X wiring task). Mirrors
 * QuickTradeForecastBacktestCommand exactly: a scheduled command, never
 * called from FutureTradeBotService::scan() or any other live scan/entry
 * path (scan() is the hot loop the live-feed daemon drives continuously --
 * adding an OHLC walk-forward backtest call there would be an expensive,
 * behavior-risking regression this task explicitly must not introduce).
 *
 * Outcome contract: Future Trade has NO fixed expiry (positions are closed
 * by trend-reversal/TP/SL logic in FutureTradeBotService::manageOpenPosition(),
 * not a timer) -- unlike Quick Trade/Robot Trade, there is no real
 * "expiry_seconds" field this bot type already measures itself against. In
 * the absence of one, this uses the same reasoned default
 * RatioBotBrainService::horizonSeconds() already uses for Ratio Bot when no
 * explicit override is configured: one candle of the bot's own signal
 * timeframe (TimeframeRegistry::seconds($timeframe)) -- "did price move in
 * the predicted direction by the next candle close", the same
 * one-candle-ahead judgment already established elsewhere in this codebase,
 * not a fabricated new horizon.
 *
 * "Active" combinations are read from future_trade_logical_trades (real
 * executed/attempted trades, both PAPER and LIVE), not from
 * FutureTradeBotConfig alone -- a single config scans and trades many
 * different symbols over time (see FutureTradeBotService::scan()'s
 * per-tick symbol ranking), so there is no single fixed (symbol, timeframe)
 * for a whole config the way RobotSession/RatioBotConfig have. This mirrors
 * Quick Trade's own reasoning for using a "{exchange}:{symbol}:{timeframe}"
 * bot_instance_id instead of a single config row id, for exactly the same
 * reason (plan correction #4: bot_instance_id must be real, stable, and
 * never null -- a config id here would silently collide across
 * unrelated symbols this config has traded, corrupting the calibration).
 */
class FutureTradeForecastBacktestCommand extends Command
{
    protected $signature = 'future-trade:forecast-backtest
        {--limit=50 : Maximum distinct symbol/timeframe combinations to backtest}';

    protected $description = 'Walk-forward calibration refresh of the Unified Forecast Engine for recently-active Future Trade symbol/timeframe combinations (tracking-only -- never affects any live scan/entry/exit decision).';

    public function handle(ForecastHorizonBacktestService $service, TimeframeRegistry $timeframes): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $exchange = 'binance_futures';

        $combinations = FutureTradeLogicalTrade::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('timeframe')
            ->selectRaw('symbol, timeframe, COUNT(*) as trade_count')
            ->groupBy('symbol', 'timeframe')
            ->orderByDesc('trade_count')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($combinations as $combination) {
            $symbol = strtoupper((string) $combination->symbol);
            $timeframe = (string) $combination->timeframe;
            $horizonSeconds = max(1, $timeframes->seconds($timeframe));
            $botInstanceId = sprintf('%s:%s:%s', $exchange, $symbol, $timeframe);

            try {
                $service->runForBot('future_trade', $botInstanceId, $exchange, $symbol, $timeframe, $horizonSeconds);
                $processed++;
            } catch (Throwable $e) {
                Log::warning('[FutureTradeForecastBacktest] Failed for combination', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'horizon_seconds' => $horizonSeconds,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Future Trade forecast calibration refreshed for {$processed}/{$combinations->count()} symbol/timeframe combinations.");

        return self::SUCCESS;
    }
}
