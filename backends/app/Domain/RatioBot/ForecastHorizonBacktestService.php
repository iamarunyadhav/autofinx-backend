<?php

namespace App\Domain\RatioBot;

use App\Domain\Trading\TimeframeRegistry;
use App\Domain\Trading\TradeForecastService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotForecastBacktest;

/**
 * Genuinely new for Ratio Bot: no existing service walks backward through
 * history to check whether a past TradeForecastService::forecast() call
 * would have been directionally right (verified before writing this --
 * TradeForecastService/SignalPreviewService/RatioBotBrainService have no
 * such logic). Walk-forward, no lookahead: at each historical index $i,
 * only candles[0..i] are fed into forecast() (exactly what would have been
 * available at that moment), and the outcome is read from the REAL candle
 * horizonCandles later -- never from data forecast() itself could see.
 *
 * NEUTRAL calls are excluded from the hit-rate on purpose -- counting them
 * either way would make an honest "did the directional call work" number
 * dishonest (a bot that mostly says NEUTRAL would look artificially
 * accurate or inaccurate depending on how ties are scored).
 *
 * === Outcome-measurement contracts (Unified Forecast Engine plan, Phase 1) ===
 *
 * This class is the seed of a shared backtest mechanism for four different
 * bot types. Each bot type's "was this prediction correct" judgment is a
 * DIFFERENT contract -- writing them down explicitly here so nobody has to
 * infer them from code later:
 *
 * - RATIO BOT (this file's pre-existing, UNCHANGED behavior --
 *   runForConfig()): outcome = was the forecast()-reported bias (BUY/SELL)
 *   directionally correct, measured by comparing the close price
 *   `horizonCandles` candles after the prediction point to the close price
 *   AT the prediction point, on the bot's own configured timeframe. NEUTRAL
 *   predictions are excluded from sample_size/hits entirely (see docblock
 *   above). This is Ratio Bot's real, existing contract -- documented here
 *   for comparison, not changed.
 *
 * - QUICK TRADE / ROBOT TRADE / FUTURE TRADE (candle-based, to be wired via
 *   runForBot() in a LATER task, not this one): outcome = did price move in
 *   the predicted direction by the time `horizon_seconds` worth of CLOSED
 *   candles have elapsed after the prediction, measured off the actual
 *   close price of the candle at that point -- not a fabricated fill price,
 *   not an intra-candle touch. Same mechanism `runForBot()`'s walk-forward
 *   loop already implements (reused, not duplicated, when that wiring
 *   happens): the exact candle `horizonCandles` after the prediction index,
 *   never an earlier or interpolated one.
 *
 * - FAST2X (tick-based, to be wired in a LATER task -- this contract is
 *   decided now only because the schema/migration needs to accommodate it):
 *   outcome measured against the last TRADE price (not bid/ask spread-
 *   crossing) at `horizon_seconds` after the tick-level prediction, BEFORE
 *   any stop-loss/take-profit exit this engine might trigger in the
 *   meantime -- a stop/take-profit exit is a separate event from the raw
 *   direction-accuracy judgment, not folded into it. Fees/slippage are
 *   explicitly NOT netted out of this hit/miss judgment (it is a pure
 *   direction-accuracy measure, not a P&L measure) -- flagged here as a
 *   decision that whoever wires Fast2X in must explicitly confirm still
 *   holds at that time, since a raw-price-direction hit-rate that ignores
 *   real trading costs can overstate how tradeable the signal actually is.
 *
 * None of the three not-yet-wired contracts above are implemented by any
 * code in this file yet -- only `bot_type`/`bot_instance_id`/
 * `confidence_bucket` schema support exists so far (this task's scope).
 */
class ForecastHorizonBacktestService
{
    private const MAX_CANDLES = 500;
    private const MIN_SEED_CANDLES = 30;
    private const MAX_SAMPLES = 300;

    /**
     * Plan correction #2: a confidence bucket's hit_rate_pct must never be
     * presented as a trustworthy calibration figure below this many samples
     * -- mirrors this codebase's existing documented-threshold discipline
     * (e.g. ProductionReadinessService::MIN_PREDICTION_SAMPLE_SIZE, also 30).
     */
    public const MIN_BUCKET_SAMPLE_SIZE = 30;

    /** Default confidence bands used by runForBot() when the caller doesn't specify its own. */
    public const DEFAULT_CONFIDENCE_BUCKETS = ['50-60', '60-70', '70-80', '80-90', '90-100'];

    /** Sentinel bucket label for Ratio Bot's own unbucketed, aggregate-hit-rate write path (runForConfig()). */
    public const AGGREGATE_BUCKET = 'all';

    public function __construct(
        private readonly TradeForecastService $forecast,
        private readonly RatioBotBrainService $brain,
        private readonly TimeframeRegistry $timeframes,
    ) {
    }

    /**
     * Ratio Bot's own entry point -- UNCHANGED aggregate behavior. Verified
     * before generalizing (Phase 1 audit, see this class's own PR/report):
     * every real consumer of `RatioBotForecastBacktest`
     * (`PredictionAccuracyService::forConfig()`, `ProductionReadinessService`,
     * `RatioBotController::trendScore()`) queries by
     * `ratio_bot_config_id` + `horizon_seconds` only, expecting exactly ONE
     * row per (config, horizon). Retroactively bucketing Ratio Bot's own
     * data by confidence would silently produce multiple rows per
     * (config, horizon) and break that assumption (non-deterministic
     * `->first()` picks, inflated `sum('sample_size')` totals) -- so this
     * method keeps writing exactly one row per (config, horizon), tagged
     * with the new `bot_type='ratio_bot'` / `bot_instance_id=$config->id` /
     * `confidence_bucket='all'` columns purely so the schema's new NOT NULL
     * constraints are satisfied, never introducing a second row.
     */
    public function runForConfig(RatioBotConfig $config): ?RatioBotForecastBacktest
    {
        if (! (bool) $config->brain_enabled) {
            return null;
        }

        $timeframe = $config->timeFrame();
        $horizonSeconds = $this->brain->horizonSeconds($config);

        $records = $this->walkForward($config->exchangeKey(), $config->normalizedSymbol(), $timeframe, $horizonSeconds);
        if ($records === null) {
            return null;
        }

        $total = count($records);
        $hits = 0;
        $confidenceSum = 0.0;
        foreach ($records as $record) {
            if ($record['hit']) {
                $hits++;
            }
            $confidenceSum += $record['confidence'];
        }

        return RatioBotForecastBacktest::updateOrCreate(
            ['ratio_bot_config_id' => $config->id, 'horizon_seconds' => $horizonSeconds],
            [
                'bot_type' => 'ratio_bot',
                'bot_instance_id' => (string) $config->id,
                'confidence_bucket' => self::AGGREGATE_BUCKET,
                'sample_size' => $total,
                'hits' => $hits,
                'hit_rate_pct' => $total > 0 ? round(($hits / $total) * 100, 2) : null,
                'avg_confidence_pct' => $total > 0 ? round($confidenceSum / $total, 2) : null,
                'computed_at' => now(),
            ],
        );
    }

    /**
     * Generalized entry point (Phase 1 foundation -- no bot type besides
     * 'ratio_bot' actually calls this yet; wiring Quick Trade/Robot
     * Trade/Future Trade/Fast2X into it is a separate, later task). Reuses
     * the exact same walk-forward mechanism as runForConfig() via
     * walkForward() (not duplicated), but tracks sample_size/hits/hit_rate
     * PER confidence bucket instead of one aggregate figure, per plan
     * correction #2 -- real calibration requires knowing whether "80%
     * confidence" predictions actually land ~80% of the time, which an
     * aggregate hit-rate across all confidence levels cannot show.
     *
     * `$botInstanceId` must be a real, concrete, non-empty identifier (plan
     * correction #4) -- never null/empty, since the unique index treats
     * distinct values (including hypothetical nulls) as distinct rows.
     *
     * @param  string[]  $confidenceBuckets  Labels like '50-60' (low-high,
     *         both in the same units as TradeForecastService::forecast()'s
     *         'confidence' -- roughly 35-95). The upper bound of a bucket is
     *         inclusive only when it is the last bucket's own upper bound
     *         (so a confidence of exactly 100 is captured somewhere) --
     *         every other bucket boundary is [low, high).
     * @return array{
     *   bot_type:string, bot_instance_id:string, horizon_seconds:int,
     *   insufficient_history:bool,
     *   buckets:array<string, array{
     *     sample_size:int, hits:int, raw_hit_rate_pct:?float,
     *     hit_rate_pct:?float, calibrated:bool, avg_confidence_pct:?float,
     *   }>,
     * }
     */
    public function runForBot(
        string $botType,
        string $botInstanceId,
        string $exchange,
        string $symbol,
        string $timeframe,
        int $horizonSeconds,
        array $confidenceBuckets = self::DEFAULT_CONFIDENCE_BUCKETS,
    ): array {
        $records = $this->walkForward($exchange, $symbol, $timeframe, $horizonSeconds);
        $insufficientHistory = $records === null;
        $records ??= [];

        $result = [
            'bot_type' => $botType,
            'bot_instance_id' => $botInstanceId,
            'horizon_seconds' => $horizonSeconds,
            'insufficient_history' => $insufficientHistory,
            'buckets' => [],
        ];

        foreach ($confidenceBuckets as $bucketLabel) {
            [$low, $high, $inclusiveHigh] = $this->parseConfidenceBucket($bucketLabel);

            $inBucket = array_values(array_filter($records, static function (array $record) use ($low, $high, $inclusiveHigh): bool {
                $confidence = $record['confidence'];

                return $inclusiveHigh
                    ? ($confidence >= $low && $confidence <= $high)
                    : ($confidence >= $low && $confidence < $high);
            }));

            $sampleSize = count($inBucket);
            $hits = count(array_filter($inBucket, static fn (array $r): bool => $r['hit']));
            $rawHitRatePct = $sampleSize > 0 ? round(($hits / $sampleSize) * 100, 2) : null;
            $calibrated = $sampleSize >= self::MIN_BUCKET_SAMPLE_SIZE;
            $avgConfidencePct = $sampleSize > 0
                ? round(array_sum(array_column($inBucket, 'confidence')) / $sampleSize, 2)
                : null;

            $result['buckets'][$bucketLabel] = [
                'sample_size' => $sampleSize,
                'hits' => $hits,
                // Always present for debugging/auditing, but never the key a
                // downstream consumer should trust below the sample floor.
                'raw_hit_rate_pct' => $rawHitRatePct,
                // Null (not "a small-sample number that looks trustworthy")
                // whenever calibrated is false -- plan correction #2.
                'hit_rate_pct' => $calibrated ? $rawHitRatePct : null,
                'calibrated' => $calibrated,
                'avg_confidence_pct' => $avgConfidencePct,
            ];

            if (! $insufficientHistory) {
                RatioBotForecastBacktest::updateOrCreate(
                    [
                        'bot_type' => $botType,
                        'bot_instance_id' => $botInstanceId,
                        'horizon_seconds' => $horizonSeconds,
                        'confidence_bucket' => $bucketLabel,
                    ],
                    [
                        'sample_size' => $sampleSize,
                        'hits' => $hits,
                        'hit_rate_pct' => $rawHitRatePct,
                        'avg_confidence_pct' => $avgConfidencePct,
                        'computed_at' => now(),
                    ],
                );
            }
        }

        return $result;
    }

    /**
     * @return array{0: float, 1: float, 2: bool} [low, high, inclusiveHigh]
     */
    private static function parseConfidenceBucket(string $label): array
    {
        $parts = explode('-', $label, 2);
        $low = (float) ($parts[0] ?? 0);
        $high = (float) ($parts[1] ?? $low);

        return [$low, $high, $high >= 100.0];
    }

    /**
     * Additive helper (Unified Forecast Engine, Phase 4b) so a caller that
     * only has a raw confidence percentage (e.g. UnifiedForecastEngine's
     * trend-composite-implied confidence) can find which of this class's
     * own bucket labels it falls into, without duplicating the
     * low/high/inclusive-high parsing rules defined above. Falls back to
     * the last bucket if nothing matches (should only happen for an
     * out-of-range input) so a caller always gets a usable label back.
     *
     * @param  string[]  $buckets
     */
    public static function bucketForConfidence(float $confidencePct, array $buckets = self::DEFAULT_CONFIDENCE_BUCKETS): string
    {
        foreach ($buckets as $label) {
            [$low, $high, $inclusiveHigh] = self::parseConfidenceBucket($label);
            $inRange = $inclusiveHigh
                ? ($confidencePct >= $low && $confidencePct <= $high)
                : ($confidencePct >= $low && $confidencePct < $high);
            if ($inRange) {
                return $label;
            }
        }

        return end($buckets) ?: self::AGGREGATE_BUCKET;
    }

    /**
     * The real walk-forward mechanism, extracted verbatim from the
     * pre-generalization runForConfig() so runForBot() can reuse it without
     * duplicating the no-lookahead logic. Returns null when there isn't
     * even enough closed-candle history to run a single valid walk-forward
     * window (mirrors the original method's early-return-null contract);
     * otherwise returns one record per historical index that produced an
     * available, non-NEUTRAL forecast() call (an empty array is a valid,
     * meaningful "there was enough history, but nothing ever called a
     * direction" result, distinct from null).
     *
     * No-lookahead guarantees (verified, Phase 2 of the Unified Forecast
     * Engine plan -- see this class's PR/report for the point-by-point
     * confirmation):
     * (a) `$slice = array_slice($candles, 0, $i + 1)` only ever contains
     *     candles up to and including index $i -- forecast() never sees
     *     candle $i+1 or later.
     * (b) the outcome (`$candles[$i + $horizonCandles]`) is only read once
     *     the loop reaches an index $i where that candle already exists in
     *     the historical array pulled up-front -- i.e. only after the full
     *     horizon has actually elapsed in the historical data, never
     *     projected/estimated.
     * (c) each iteration of the outer loop scores exactly one (index,
     *     outcome) pair and advances by `$step` (>= 1); windows are never
     *     re-scored against a later, overlapping outcome index in the same
     *     run, so sample_size reflects `$iterations / $step` real,
     *     non-doubly-counted observations, not an inflated one.
     *
     * @return array<int, array{confidence: float, hit: bool}>|null
     */
    private function walkForward(string $exchange, string $symbol, string $timeframe, int $horizonSeconds): ?array
    {
        $tfSeconds = max(1, $this->timeframes->seconds($timeframe));
        $horizonCandles = max(1, (int) ceil($horizonSeconds / $tfSeconds));

        $candles = MarketOhlc::query()
            ->where('exchange', $exchange)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->orderByDesc('ts')
            ->limit(self::MAX_CANDLES)
            ->get(['open', 'high', 'low', 'close'])
            ->reverse()
            ->values()
            ->map(fn ($candle) => [
                'open' => (float) $candle->open,
                'high' => (float) $candle->high,
                'low' => (float) $candle->low,
                'close' => (float) $candle->close,
            ])
            ->all();

        $count = count($candles);
        $lastIndex = $count - $horizonCandles - 1;
        if ($lastIndex < self::MIN_SEED_CANDLES) {
            return null;
        }

        $iterations = $lastIndex - self::MIN_SEED_CANDLES + 1;
        $step = $iterations > self::MAX_SAMPLES ? (int) ceil($iterations / self::MAX_SAMPLES) : 1;

        $records = [];

        for ($i = self::MIN_SEED_CANDLES; $i <= $lastIndex; $i += $step) {
            $slice = array_slice($candles, 0, $i + 1);
            $result = $this->forecast->forecast($slice, $timeframe, $horizonSeconds);
            if (! ($result['available'] ?? false)) {
                continue;
            }

            $bias = $result['bias'] ?? 'NEUTRAL';
            if ($bias === 'NEUTRAL') {
                continue;
            }

            $currentClose = (float) $slice[count($slice) - 1]['close'];
            if ($currentClose <= 0) {
                continue;
            }
            $futureClose = (float) $candles[$i + $horizonCandles]['close'];
            $actualMove = ($futureClose / $currentClose) - 1;
            $correct = ($bias === 'BUY' && $actualMove > 0) || ($bias === 'SELL' && $actualMove < 0);

            $records[] = [
                'confidence' => (float) ($result['confidence'] ?? 0),
                'hit' => $correct,
            ];
        }

        return $records;
    }
}
