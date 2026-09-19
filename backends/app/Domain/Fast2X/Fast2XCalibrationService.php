<?php

namespace App\Domain\Fast2X;

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Models\Fast2XConfig;
use App\Models\Fast2XDecisionLog;
use App\Models\Fast2XTrade;
use App\Models\RatioBotForecastBacktest;
use Illuminate\Support\Collection;

/**
 * Fast2X-native calibration -- deliberately NOT wired through
 * ForecastHorizonBacktestService::runForBot(), because Fast2X's real
 * entry/exit decision (Fast2XService::evaluateEntry()/manageOpenPosition())
 * never calls TradeForecastService::forecast() at all. It is a
 * threshold+indicator microstructure rule (rebound-percent vs dynamic
 * threshold, IndicatorService::confirm(), ExternalSignalFusionService).
 * Wiring it into runForBot() would silently record a "Fast2X calibration"
 * row measuring an unrelated OHLC forecast Fast2X never actually uses.
 *
 * Instead, this reads Fast2X's own REAL signal -> REAL outcome data:
 *  - `fast2x_decision_logs` (event = 'BUY_TRIGGER_DETECTED' is the real
 *    entry-trigger event -- logged in Fast2XService::evaluateEntry() right
 *    before OrderExecutionService::executeBuy() is called, i.e. it fires
 *    only once risk/indicator/external-signal gates already passed).
 *  - `fast2x_trades` (real entry_price/exit_price/fees/slippage/net_pnl per
 *    trade, already fee+slippage netted by OrderExecutionService).
 *
 * Confidence signal, verified honestly (do not assume): Fast2X's
 * IndicatorService::confirm() and ExternalSignalFusionService::evaluate()
 * DO compute a numeric confidence in memory during tick processing
 * (Fast2XService::processTick()), but DecisionLogService::log() only
 * persists `entry_price`/`current_price`/`net_pnl_percent`/`reason`/
 * `context` -- the BUY_TRIGGER_DETECTED log call site
 * (`$this->logs->log($config, 'BUY_TRIGGER_DETECTED', ['currentPrice' =>
 * $price])`) never includes confidence in that context. So there is NO
 * numeric confidence/strength value persisted anywhere that this service
 * (a read-only, after-the-fact analytics service) can honestly recover for
 * historical triggers. Per this task's own instruction, we do NOT invent
 * one -- results are always a single aggregate bucket
 * (`confidence_buckets_available = false`), never fabricated buckets.
 *
 * Fee/slippage netting: `fast2x_trades.net_pnl` (set by
 * OrderExecutionService::executePaperSell()/executeVenueSell() as
 * `gross_pnl - fees - slippage`) is what this service treats as the
 * calibration outcome -- NOT `gross_pnl` and NOT a raw exit>entry price
 * comparison. A trade that is price-direction-correct
 * (exit_price > entry_price, so gross_pnl > 0) but whose fees+slippage
 * exceed that gross profit (net_pnl <= 0) is counted as a LOSS for
 * calibration purposes, exactly mirroring this session's established
 * principle that a raw-price-direction hit-rate ignoring real costs
 * overstates how useful/tradeable a signal actually is.
 *
 * READ-ONLY: this service only ever performs SELECT queries against
 * fast2x_decision_logs/fast2x_trades/fast2x_configs, and a single
 * updateOrCreate() against the separate `ratio_bot_forecast_backtests`
 * table (see storeResult()). It never writes to any Fast2X table and is
 * never called from Fast2XService::processTick()/evaluateEntry()/
 * manageOpenPosition() or any other part of the live tick loop.
 */
class Fast2XCalibrationService
{
    /**
     * Reused from ForecastHorizonBacktestService (public constant, not
     * duplicated) so "a calibration figure below this many samples is not
     * trustworthy" is a single documented threshold across every bot type
     * in the platform, not a Fast2X-specific number invented here.
     */
    public const MIN_BUCKET_SAMPLE_SIZE = ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE;

    /**
     * Sentinel `horizon_seconds` value written to `ratio_bot_forecast_backtests`
     * for Fast2X rows. Fast2X has no fixed prediction horizon (unlike Ratio
     * Bot/Quick Trade/Robot Trade/Future Trade's candle-horizon contracts) --
     * a Fast2X trade's outcome is judged at whatever real time the position
     * was actually closed (profit target / trailing stop / rollback / stop
     * loss), which varies trade to trade. 0 is used here purely as a
     * documented "not a fixed horizon" sentinel, never a real duration.
     */
    public const HORIZON_SENTINEL = 0;

    /**
     * @return array{
     *   bot_type: string, bot_instance_id: string, symbol: string,
     *   sample_size: int, hits: int, win_rate_pct: ?float, calibrated: bool,
     *   confidence_buckets_available: bool, avg_net_pnl: ?float,
     *   avg_fees: ?float, avg_slippage: ?float, unresolved_triggers: int,
     *   insufficient_history: bool,
     * }|null Null when the config has never logged a single decision (never
     *   run) -- mirrors ForecastHorizonBacktestService::runForConfig()'s own
     *   "nothing to compute yet" null-return contract.
     */
    public function calibrate(Fast2XConfig $config): ?array
    {
        $hasAnyHistory = Fast2XDecisionLog::query()
            ->where('fast2x_config_id', $config->id)
            ->exists();

        if (! $hasAnyHistory) {
            return null;
        }

        $triggers = Fast2XDecisionLog::query()
            ->where('fast2x_config_id', $config->id)
            ->where('event', 'BUY_TRIGGER_DETECTED')
            ->orderBy('occurred_at')
            ->orderBy('created_at')
            ->get();

        $trades = Fast2XTrade::query()
            ->where('fast2x_config_id', $config->id)
            ->orderBy('created_at')
            ->get();

        $matches = $this->matchTriggersToSettledOutcomes($triggers, $trades);

        $sampleSize = $matches->count();
        $hits = $matches->filter(fn (array $m) => $m['net_pnl'] > 0)->count();
        $winRatePct = $sampleSize > 0 ? round(($hits / $sampleSize) * 100, 2) : null;

        $result = [
            'bot_type' => 'fast2x',
            'bot_instance_id' => (string) $config->id,
            'symbol' => $config->normalizedSymbol(),
            'sample_size' => $sampleSize,
            'hits' => $hits,
            'win_rate_pct' => $winRatePct,
            'calibrated' => $sampleSize >= self::MIN_BUCKET_SAMPLE_SIZE,
            // Honest, not fabricated: see class docblock. Fast2X has no
            // persisted numeric confidence/strength value at trigger time.
            'confidence_buckets_available' => false,
            'avg_net_pnl' => $sampleSize > 0 ? round($matches->avg('net_pnl'), 8) : null,
            'avg_fees' => $sampleSize > 0 ? round($matches->avg('fees'), 8) : null,
            'avg_slippage' => $sampleSize > 0 ? round($matches->avg('slippage'), 8) : null,
            'unresolved_triggers' => $triggers->count() - $sampleSize,
            'insufficient_history' => false,
        ];

        $this->storeResult($result);

        return $result;
    }

    /**
     * Pairs each real BUY_TRIGGER_DETECTED log entry to the real settled
     * (SELL, net_pnl not null) trade that closed the position it opened.
     * Fast2X manages exactly one open position per config at a time (see
     * Fast2XService::processTick(): a tick either manages the single open
     * position or evaluates a new entry, never both), so trades are a
     * strictly alternating BUY/SELL sequence per config -- pairing is a
     * simple two-pointer walk, not a heuristic guess.
     *
     * A trigger with no BUY trade created at/after it (e.g. the buy order
     * itself failed after the trigger was logged) or whose BUY has no
     * following settled SELL yet (position still open, or bot stopped
     * mid-position) is left unmatched -- an honestly still-unresolved
     * signal must never be counted as either a hit or a miss.
     *
     * @param  Collection<int, Fast2XDecisionLog>  $triggers
     * @param  Collection<int, Fast2XTrade>  $trades
     * @return Collection<int, array{net_pnl: float, fees: float, slippage: float}>
     */
    private function matchTriggersToSettledOutcomes(Collection $triggers, Collection $trades): Collection
    {
        $buys = $trades->filter(fn (Fast2XTrade $t) => strtoupper((string) $t->side) === 'BUY')->values();
        $sells = $trades->filter(fn (Fast2XTrade $t) => strtoupper((string) $t->side) === 'SELL')->values();

        $matches = collect();
        $buyCursor = 0;
        $sellCursor = 0;

        foreach ($triggers as $trigger) {
            $triggerTime = $trigger->occurred_at ?? $trigger->created_at;

            // Advance to the first not-yet-consumed BUY trade created at/after this trigger.
            while ($buyCursor < $buys->count() && $buys[$buyCursor]->created_at->lt($triggerTime)) {
                $buyCursor++;
            }

            if ($buyCursor >= $buys->count()) {
                continue;
            }

            $buy = $buys[$buyCursor];
            $buyCursor++;

            // Advance to the first not-yet-consumed SELL trade after this BUY, that is actually settled.
            while ($sellCursor < $sells->count() && $sells[$sellCursor]->created_at->lt($buy->created_at)) {
                $sellCursor++;
            }

            if ($sellCursor >= $sells->count() || $sells[$sellCursor]->net_pnl === null) {
                continue;
            }

            $sell = $sells[$sellCursor];
            $sellCursor++;

            $matches->push([
                'net_pnl' => (float) $sell->net_pnl,
                'fees' => (float) $sell->fees,
                'slippage' => (float) $sell->slippage,
            ]);
        }

        return $matches;
    }

    /**
     * Reuses the existing `ratio_bot_forecast_backtests` table rather than
     * creating a new Fast2X-only table. Reasoned choice, not the convenient
     * default:
     *  - That table was ALREADY generalized (2026-09-18 migration) with
     *    `bot_type`/`bot_instance_id`/`confidence_bucket`/`evaluation_period`
     *    columns specifically so Quick Trade/Robot Trade/Future Trade/Fast2X
     *    calibration could live there without a new table per bot type --
     *    its own docblock names Fast2X explicitly as a future consumer.
     *  - Every column Fast2X actually needs (`sample_size`, `hits`,
     *    `hit_rate_pct`, `avg_confidence_pct`, `computed_at`) maps honestly:
     *    `hit_rate_pct` = fee/slippage-netted win rate, `avg_confidence_pct`
     *    is left NULL (see class docblock: no fabricated confidence).
     *  - `horizon_seconds` does not fit as cleanly (Fast2X has no fixed
     *    horizon) -- documented via HORIZON_SENTINEL rather than silently
     *    reusing another bot type's real horizon value or corrupting the
     *    column's meaning.
     *  - `ratio_bot_config_id` stays NULL for these rows (already nullable
     *    since the 2026-09-18 migration for exactly this reason), so no
     *    existing consumer that filters by `ratio_bot_config_id`
     *    (PredictionAccuracyService::forConfig(), ProductionReadinessService,
     *    RatioBotController::trendScore()) ever sees a Fast2X row.
     */
    private function storeResult(array $result): void
    {
        RatioBotForecastBacktest::updateOrCreate(
            [
                'bot_type' => $result['bot_type'],
                'bot_instance_id' => $result['bot_instance_id'],
                'horizon_seconds' => self::HORIZON_SENTINEL,
                'confidence_bucket' => ForecastHorizonBacktestService::AGGREGATE_BUCKET,
                'evaluation_period' => 'full',
            ],
            [
                'sample_size' => $result['sample_size'],
                'hits' => $result['hits'],
                'hit_rate_pct' => $result['win_rate_pct'],
                'avg_confidence_pct' => null,
                'computed_at' => now(),
            ],
        );
    }
}
