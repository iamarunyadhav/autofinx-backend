<?php

namespace App\Domain\RatioBot;

use App\Domain\Trading\ForecastMetricsService;
use App\Models\ForecastBacktestPrediction;
use App\Models\RatioBotForecastBacktest;

/**
 * READ-ONLY calibration-quality analytics (Unified Forecast Engine plan --
 * "confidence calibration quality" task). Never writes to
 * `RatioBotForecastBacktest` or any other table, never wired into any live
 * execution path -- every method here is a pure read (of rows already
 * written by `ForecastHorizonBacktestService::runForBot()` /
 * `runForBotWithHoldout()`) + compute.
 *
 * Data source and its real shape (verified against
 * `ForecastHorizonBacktestService` and the
 * `2026_09_18_000000_add_bot_type_and_confidence_bucket_...` /
 * `2026_09_19_000000_add_evaluation_period_...` migrations before writing
 * this): `RatioBotForecastBacktest` stores exactly one row per
 * (bot_type, bot_instance_id, horizon_seconds, confidence_bucket,
 * evaluation_period) -- a BUCKET-LEVEL AGGREGATE (sample_size, hits,
 * hit_rate_pct, avg_confidence_pct). There is NO per-prediction table
 * anywhere in this codebase that records each individual forecast's
 * confidence and its individual hit/miss outcome -- `walkForward()` builds
 * those per-prediction records only transiently, in memory, inside
 * `ForecastHorizonBacktestService`, and only the bucket aggregate survives
 * to disk. Also note: there is no persisted `calibrated` boolean column --
 * every consumer of this table (this class included) computes it on the fly
 * as `sample_size >= ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE`,
 * exactly like `ForecastHorizonBacktestService`/`ForecastModelPromotionGate`
 * already do.
 *
 * Consequence: the Brier score computed below is a **bucket-weighted
 * approximation**, not a true per-prediction Brier score -- see
 * `evaluate()`'s docblock for the exact formula and why.
 *
 * === Exact scoring (additive, exact-Brier-score task) ===
 * `forecast_backtest_predictions` (see that migration's docblock) now
 * stores one row per individual walk-forward prediction, going forward,
 * for every scope `ForecastHorizonBacktestService::runForConfig()` /
 * `runForBot()` / `runForBotWithHoldout()` writes. Where that table HAS
 * rows for a given (bot_type, bot_instance_id, horizon_seconds,
 * evaluation_period) scope, `evaluate()` now ALSO computes a true,
 * non-approximated `brier_score_exact` -- `(1/N) * Σ(p_i - o_i)²` over each
 * individual prediction's REAL confidence `p_i` and real outcome `o_i` --
 * and an exact, freshly-grouped reliability curve, alongside (never
 * replacing) the pre-existing bucket-weighted approximation. Scopes backtest
 * BEFORE this table existed (or never re-run since) have no per-prediction
 * rows; for those, `exact_available` is reported `false` and only the
 * approximation is available -- this class never silently substitutes one
 * for the other.
 */
class ForecastCalibrationQualityService
{
    /**
     * Reasoned default: a bucket whose real, sample-floor-cleared hit rate
     * sits more than 10 percentage points below its own displayed
     * confidence midpoint is flagged as overconfident (e.g. "displayed 85%
     * confidence, actually correct only 62% of the time" -- 23pp gap, well
     * past this threshold). 10pp is a deliberately conservative bar (not
     * every bit of sampling noise around a bucket's true rate should trip a
     * warning) rather than a number tuned to any specific bot; named here so
     * it can be revisited deliberately rather than buried inline.
     */
    public const OVERCONFIDENCE_THRESHOLD_PP = 10.0;

    /**
     * Produces the reliability-diagram data, a bucket-weighted-approximate
     * Brier score, and an overconfidence-warning list for one
     * (bot_type, bot_instance_id, horizon_seconds, evaluation_period) scope.
     *
     * === Reliability curve ===
     * One entry per bucket in `$confidenceBuckets` (default: the same
     * `ForecastHorizonBacktestService::DEFAULT_CONFIDENCE_BUCKETS`), pairing
     * the bucket's displayed-confidence midpoint (e.g. '80-90' -> 85) against
     * its real `hit_rate_pct` from the stored row -- exactly the two axes a
     * reliability diagram plots. A bucket with no stored row at all (never
     * backtested) is included with `sample_size: 0` and a null hit rate,
     * never fabricated. `actual_hit_rate_pct` is null whenever the bucket
     * has NOT cleared `MIN_BUCKET_SAMPLE_SIZE` (fail-closed, matching this
     * whole session's discipline) -- `raw_hit_rate_pct` is included
     * alongside it, ungated, purely for debugging/auditing, mirroring
     * `ForecastHorizonBacktestService::runForBot()`'s own
     * raw/gated-pair convention.
     *
     * === Brier score (bucket-weighted APPROXIMATION, not a true
     * per-prediction score) ===
     * The standard Brier score is `(1/N) * Σ(predicted_prob_i -
     * actual_outcome_i)²` over individual predictions i, where
     * actual_outcome_i ∈ {0, 1}. That per-prediction data does not exist in
     * this codebase (see class docblock) -- only, per bucket b: sample_size
     * n_b, hits h_b, and avg_confidence_pct (the bucket's own real mean
     * confidence, used here as the stand-in predicted probability p_b =
     * avg_confidence_pct / 100 for every prediction in that bucket, since
     * the true per-prediction confidence values are not retained).
     *
     * Treating every prediction in bucket b as if it had WHEREVER predicted
     * probability p_b, the sum of squared errors for that bucket is exactly:
     *   h_b * (1 - p_b)²  +  (n_b - h_b) * (0 - p_b)²
     * (h_b hits each score (p_b - 1)², the n_b - h_b misses each score
     * (p_b - 0)² -- this part is exact given the hit/miss COUNTS, which are
     * real; the approximation is entirely in assuming a single p_b for every
     * prediction inside the bucket, when real per-prediction confidences
     * vary continuously within [low, high)).
     *
     * The overall approximate Brier score is the sample-size-weighted mean
     * across buckets:
     *   Σ_b [ h_b*(1-p_b)² + (n_b-h_b)*p_b² ]  /  Σ_b n_b
     *
     * Buckets with `sample_size === 0` contribute nothing (no data to
     * weight in). The overall score is reported as null (never a
     * small-sample number dressed up as meaningful) unless the combined
     * sample size across all included buckets clears
     * `ForecastMetricsService::MIN_TRUSTED_SAMPLES` (30) -- the same floor
     * this session's other analytics already use.
     *
     * === Overconfidence warning ===
     * Any bucket where `sample_size >= MIN_BUCKET_SAMPLE_SIZE` (i.e. it IS
     * calibrated -- an uncalibrated bucket's rate is too noisy to accuse of
     * anything) AND `confidence_midpoint - hit_rate_pct >
     * OVERCONFIDENCE_THRESHOLD_PP` is reported, with the exact gap.
     *
     * @param  string[]  $confidenceBuckets
     * @return array{
     *   bot_type:string, bot_instance_id:string, horizon_seconds:int,
     *   evaluation_period:string,
     *   reliability_curve:array<int, array{
     *     confidence_bucket:string, confidence_midpoint:float,
     *     actual_hit_rate_pct:?float, raw_hit_rate_pct:?float,
     *     sample_size:int, calibrated:bool,
     *   }>,
     *   brier_score:?float, brier_score_sample_size:int,
     *   brier_score_is_approximation:bool,
     *   overconfident_buckets:array<int, array{
     *     confidence_bucket:string, confidence_midpoint:float,
     *     actual_hit_rate_pct:float, gap_percentage_points:float,
     *     sample_size:int,
     *   }>,
     *   overconfidence_threshold_pp:float,
     *   exact_available:bool, brier_score_exact:?float,
     *   brier_score_exact_sample_size:int,
     * }
     */
    public function evaluate(
        string $botType,
        string $botInstanceId,
        int $horizonSeconds,
        string $evaluationPeriod = 'holdout',
        array $confidenceBuckets = ForecastHorizonBacktestService::DEFAULT_CONFIDENCE_BUCKETS,
    ): array {
        $rows = RatioBotForecastBacktest::query()
            ->where('bot_type', $botType)
            ->where('bot_instance_id', $botInstanceId)
            ->where('horizon_seconds', $horizonSeconds)
            ->where('evaluation_period', $evaluationPeriod)
            ->get()
            ->keyBy('confidence_bucket');

        // Additive (exact-Brier-score task): every individual prediction
        // this scope has ever had persisted (going forward from this
        // task's migration). Empty for any scope backtested before that
        // migration existed, or never re-run since -- handled below via
        // $exactAvailable, never silently treated as "zero predictions
        // means the bot never predicted anything".
        $predictions = ForecastBacktestPrediction::query()
            ->where('bot_type', $botType)
            ->where('bot_instance_id', $botInstanceId)
            ->where('horizon_seconds', $horizonSeconds)
            ->where('evaluation_period', $evaluationPeriod)
            ->get(['confidence_pct', 'predicted_hit']);
        $exactAvailable = $predictions->isNotEmpty();

        $reliabilityCurve = [];
        $overconfidentBuckets = [];
        $brierSquaredErrorSum = 0.0;
        $brierSampleTotal = 0;
        $exactBrierSquaredErrorSum = 0.0;
        $exactBrierSampleTotal = 0;

        foreach ($confidenceBuckets as $bucketLabel) {
            $row = $rows->get($bucketLabel);
            $midpoint = self::bucketMidpoint($bucketLabel);
            $sampleSize = $row ? (int) $row->sample_size : 0;
            $hits = $row ? (int) $row->hits : 0;
            $rawHitRatePct = ($row && $row->hit_rate_pct !== null) ? (float) $row->hit_rate_pct : null;
            $calibrated = $sampleSize >= ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE;

            // Exact reliability curve for this same bucket, computed FRESH
            // from raw per-prediction rows (never from the approximated
            // $row's pre-aggregated hit_rate_pct/avg_confidence_pct) --
            // only meaningful when $exactAvailable.
            [$low, $high, $inclusiveHigh] = ForecastHorizonBacktestService::parseConfidenceBucket($bucketLabel);
            $exactInBucket = $exactAvailable
                ? $predictions->filter(static function ($prediction) use ($low, $high, $inclusiveHigh): bool {
                    $confidence = (float) $prediction->confidence_pct;

                    return $inclusiveHigh
                        ? ($confidence >= $low && $confidence <= $high)
                        : ($confidence >= $low && $confidence < $high);
                })
                : collect();
            $exactSampleSize = $exactInBucket->count();
            $exactHits = $exactInBucket->filter(static fn ($p) => (bool) $p->predicted_hit)->count();
            $exactCalibrated = $exactSampleSize >= ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE;
            $exactHitRatePct = $exactSampleSize > 0 ? round(($exactHits / $exactSampleSize) * 100, 2) : null;

            $reliabilityCurve[] = [
                'confidence_bucket' => $bucketLabel,
                'confidence_midpoint' => $midpoint,
                'actual_hit_rate_pct' => $calibrated ? $rawHitRatePct : null,
                'raw_hit_rate_pct' => $rawHitRatePct,
                'sample_size' => $sampleSize,
                'calibrated' => $calibrated,
                // Exact companions -- null/false whenever this bucket has no
                // per-prediction rows yet, never fabricated from the
                // approximated figures above.
                'exact_available' => $exactSampleSize > 0,
                'exact_sample_size' => $exactSampleSize,
                'exact_hit_rate_pct' => $exactCalibrated ? $exactHitRatePct : null,
                'exact_raw_hit_rate_pct' => $exactHitRatePct,
                'exact_calibrated' => $exactCalibrated,
            ];

            if ($calibrated && $rawHitRatePct !== null) {
                $gap = $midpoint - $rawHitRatePct;
                if ($gap > self::OVERCONFIDENCE_THRESHOLD_PP) {
                    $overconfidentBuckets[] = [
                        'confidence_bucket' => $bucketLabel,
                        'confidence_midpoint' => $midpoint,
                        'actual_hit_rate_pct' => $rawHitRatePct,
                        'gap_percentage_points' => round($gap, 2),
                        'sample_size' => $sampleSize,
                    ];
                }
            }

            if ($sampleSize > 0 && $rawHitRatePct !== null) {
                $avgConfidencePct = ($row && $row->avg_confidence_pct !== null)
                    ? (float) $row->avg_confidence_pct
                    : $midpoint;
                $predictedProbability = $avgConfidencePct / 100;
                $misses = $sampleSize - $hits;
                $brierSquaredErrorSum += $hits * (1 - $predictedProbability) ** 2
                    + $misses * ($predictedProbability ** 2);
                $brierSampleTotal += $sampleSize;
            }
        }

        $brierScore = $brierSampleTotal >= ForecastMetricsService::MIN_TRUSTED_SAMPLES
            ? round($brierSquaredErrorSum / $brierSampleTotal, 6)
            : null;

        // True per-prediction Brier score: (1/N) * Σ(p_i - o_i)² computed
        // directly over EVERY persisted prediction row for this scope (not
        // grouped by $confidenceBuckets at all -- a prediction whose real
        // confidence happens to fall outside every configured bucket
        // boundary must still count toward the exact score, since Brier
        // scoring has no notion of buckets in the first place; bucketing is
        // only ever used for the reliability curve/overconfidence checks
        // above). Same MIN_TRUSTED_SAMPLES floor as the approximation, so a
        // handful of exact rows never look more authoritative than they are.
        foreach ($predictions as $prediction) {
            $p = ((float) $prediction->confidence_pct) / 100;
            $o = $prediction->predicted_hit ? 1.0 : 0.0;
            $exactBrierSquaredErrorSum += ($p - $o) ** 2;
        }
        $exactBrierSampleTotal = $predictions->count();

        $brierScoreExact = ($exactAvailable && $exactBrierSampleTotal >= ForecastMetricsService::MIN_TRUSTED_SAMPLES)
            ? round($exactBrierSquaredErrorSum / $exactBrierSampleTotal, 6)
            : null;

        return [
            'bot_type' => $botType,
            'bot_instance_id' => $botInstanceId,
            'horizon_seconds' => $horizonSeconds,
            'evaluation_period' => $evaluationPeriod,
            'reliability_curve' => $reliabilityCurve,
            'brier_score' => $brierScore,
            'brier_score_sample_size' => $brierSampleTotal,
            'brier_score_is_approximation' => true,
            'overconfident_buckets' => $overconfidentBuckets,
            'overconfidence_threshold_pp' => self::OVERCONFIDENCE_THRESHOLD_PP,
            // Exact companions (see class docblock's "Exact scoring"
            // section) -- exact_available is strictly about whether any
            // per-prediction row exists for this scope; brier_score_exact
            // additionally requires the MIN_TRUSTED_SAMPLES floor, same as
            // the approximation.
            'exact_available' => $exactAvailable,
            'brier_score_exact' => $brierScoreExact,
            'brier_score_exact_sample_size' => $exactBrierSampleTotal,
        ];
    }

    /**
     * Displayed-confidence midpoint of a bucket label like '80-90' -> 85.0.
     * Deliberately simpler than
     * `ForecastHorizonBacktestService::parseConfidenceBucket()` (which also
     * resolves inclusive/exclusive upper-bound membership for filtering
     * records into a bucket) -- this only ever needs the two boundary
     * numbers, not the membership-test semantics, so it does not depend on
     * that private method.
     */
    public static function bucketMidpoint(string $bucketLabel): float
    {
        $parts = explode('-', $bucketLabel, 2);
        $low = (float) ($parts[0] ?? 0);
        $high = (float) ($parts[1] ?? $low);

        return ($low + $high) / 2;
    }
}
