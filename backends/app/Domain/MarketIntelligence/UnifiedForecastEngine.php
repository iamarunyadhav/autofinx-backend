<?php

namespace App\Domain\MarketIntelligence;

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Domain\RatioBot\ForecastReliabilityWeighting;
use App\Domain\RatioBot\MultiWindowTrendScoringService;
use App\Models\RatioBotForecastBacktest;

/**
 * Unified Forecast Engine (Phase 4b) -- SHADOW MODE ONLY in this pass (see
 * the Quick Trade shadow wiring in QuickTradeController::forecast()). This
 * class computes and returns a structured, explainable result; nothing in
 * this file writes to any trading/execution path, and no caller in this
 * pass lets its output change a real decision.
 *
 * Same namespace as (and matching the style of) the already-built
 * MarketAnalysisEngine/ConfidenceEngine: thin, explicit composition of
 * already-proven mechanisms, no new decision authority invented here.
 *
 * Composes, in the order documented in the class's own evaluate():
 *   1. MultiWindowTrendScoringService::scoreFor() -- the trend read.
 *   2. ForecastClarityTracker -- stateful wait/streak bookkeeping around
 *      that stateless trend read; short-circuits to an honest NEUTRAL
 *      result while clarity hasn't arrived (plan correction #1's max-wait
 *      bound).
 *   3. A read-only lookup of the LATEST matching RatioBotForecastBacktest
 *      calibration row for this (bot_type, bot_instance_id, horizon,
 *      confidence_bucket) -- never recomputes a backtest inline (that is a
 *      periodic job's job, see Phase 4c's scheduled command).
 *   4. ForecastReliabilityWeighting::weightForHitRate() -- only meaningful,
 *      and only applied, once real calibration data exists for that
 *      bucket.
 *   5. IndicatorGroupScorer -- grouped, anti-double-counted momentum /
 *      trend-confirmation / volatility-range votes, reported for
 *      explainability.
 */
class UnifiedForecastEngine
{
    public function __construct(
        private readonly MultiWindowTrendScoringService $trendScoring,
        private readonly ForecastClarityTracker $clarityTracker,
        private readonly IndicatorGroupScorer $indicatorGroups,
    ) {
    }

    /**
     * @return array{
     *   direction: string, confidence: ?float, calibrated: bool, status: string,
     *   reason: ?string, wait_ticks: int, max_wait_ticks: int,
     *   trend_composite: float, trend_implied_confidence_pct: ?float,
     *   calibration_evidence: array{sample_size:int, hit_rate_pct: ?float, confidence_bucket: ?string},
     *   reliability_weight: ?float, indicator_groups: array,
     *   why: string[],
     * }
     */
    public function evaluate(
        string $botType,
        string $botInstanceId,
        string $exchange,
        string $symbol,
        string $timeframe,
        int $horizonSeconds,
        int $baseCandles = 40,
        float $minComposite = 0.15,
    ): array {
        $why = [];

        // Step 1: stateless trend read for THIS tick.
        $trend = $this->trendScoring->scoreFor($exchange, $symbol, $timeframe, $baseCandles, $minComposite);

        // Step 2: stateful clarity/wait-streak bookkeeping around it.
        $clarity = $this->clarityTracker->track($botType, $botInstanceId, $symbol, $timeframe, $trend);

        if ($trend['status'] !== 'clear') {
            $reason = $clarity['max_wait_exceeded']
                ? 'insufficient_clarity_max_wait_exceeded'
                : 'insufficient_clarity';

            $why[] = $clarity['max_wait_exceeded']
                ? sprintf('No window has cleared the composite threshold after %d ticks (max %d) -- giving up this tick rather than waiting indefinitely.', $clarity['wait_ticks'], $clarity['max_wait_ticks'])
                : sprintf('Composite trend score %.4f has not cleared min_composite %.4f yet (wait tick %d/%d).', $trend['composite_score'], $trend['min_composite'], $clarity['wait_ticks'], $clarity['max_wait_ticks']);

            return [
                'direction' => 'NEUTRAL',
                'confidence' => null,
                'calibrated' => false,
                'status' => 'insufficient_clarity',
                'reason' => $reason,
                'wait_ticks' => $clarity['wait_ticks'],
                'max_wait_ticks' => $clarity['max_wait_ticks'],
                'trend_composite' => (float) $trend['composite_score'],
                'trend_implied_confidence_pct' => null,
                'calibration_evidence' => ['sample_size' => 0, 'hit_rate_pct' => null, 'confidence_bucket' => null],
                'reliability_weight' => null,
                'indicator_groups' => null,
                'why' => $why,
            ];
        }

        $why[] = sprintf('Trend composite %.4f cleared min_composite %.4f -- direction %s.', $trend['composite_score'], $trend['min_composite'], $trend['direction']);

        // Trend-composite-implied confidence: composite_score is already
        // clamped to [-1, 1] by MultiWindowTrendScoringService, so its
        // magnitude (guaranteed >= min_composite here, since status is
        // 'clear') maps linearly onto the same 50-100 confidence range
        // ForecastHorizonBacktestService::DEFAULT_CONFIDENCE_BUCKETS
        // already buckets by -- an explicit, documented mapping, not a
        // fabricated one.
        $impliedConfidencePct = round(min(95.0, max(50.0, 50.0 + (abs((float) $trend['composite_score']) * 45.0))), 2);
        $bucketLabel = ForecastHorizonBacktestService::bucketForConfidence($impliedConfidencePct);

        // Step 3: read-only lookup -- never recompute a backtest here.
        $calibrationRow = RatioBotForecastBacktest::query()
            ->where('bot_type', $botType)
            ->where('bot_instance_id', $botInstanceId)
            ->where('horizon_seconds', $horizonSeconds)
            ->where('confidence_bucket', $bucketLabel)
            ->latest('computed_at')
            ->first();

        $sampleSize = $calibrationRow?->sample_size !== null ? (int) $calibrationRow->sample_size : 0;
        $calibrated = $calibrationRow !== null
            && $calibrationRow->hit_rate_pct !== null
            && $sampleSize >= ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE;
        $hitRatePct = $calibrated ? (float) $calibrationRow->hit_rate_pct : null;

        if ($calibrated) {
            $why[] = sprintf('Calibration bucket %s: %d samples, %.2f%% hit rate -- treated as calibrated (>= %d sample floor).', $bucketLabel, $sampleSize, $hitRatePct, ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE);
        } else {
            $why[] = $calibrationRow === null
                ? sprintf('No calibration data yet for bucket %s -- reporting uncalibrated rather than a fabricated hit rate.', $bucketLabel)
                : sprintf('Calibration bucket %s only has %d samples (below the %d-sample floor) -- reporting uncalibrated.', $bucketLabel, $sampleSize, ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE);
        }

        // Step 4: reliability weighting -- only meaningful once calibrated.
        $reliabilityWeight = $calibrated ? ForecastReliabilityWeighting::weightForHitRate($hitRatePct) : null;

        // Step 5: grouped indicator scoring (anti-double-counting -- see
        // IndicatorGroupScorer's own docblock).
        $indicatorGroups = $this->indicatorGroups->scoreFor($exchange, $symbol, $timeframe);
        $why[] = sprintf('Indicator groups: momentum=%s, trend_confirmation=%s, volatility_range=%s -> combined=%s.', $indicatorGroups['momentum']['direction'], $indicatorGroups['trend_confirmation']['direction'], $indicatorGroups['volatility_range']['direction'], $indicatorGroups['combined_direction']);

        // Final confidence: blend the trend-implied confidence with the
        // proven hit rate at reliabilityWeight once calibrated (mirrors
        // ConfidenceEngine's own trend-vs-forecast blend, generalized);
        // left as the honest trend-implied figure, unblended, when there is
        // nothing proven yet to blend it with.
        $confidencePct = $calibrated
            ? round(($impliedConfidencePct * (1 - $reliabilityWeight)) + ($hitRatePct * $reliabilityWeight), 2)
            : $impliedConfidencePct;

        $direction = strtoupper($trend['direction']) === 'BULLISH' ? 'BUY' : (strtoupper($trend['direction']) === 'BEARISH' ? 'SELL' : 'NEUTRAL');

        return [
            'direction' => $direction,
            'confidence' => $confidencePct,
            'calibrated' => $calibrated,
            'status' => 'clear',
            'reason' => null,
            'wait_ticks' => 0,
            'max_wait_ticks' => $clarity['max_wait_ticks'],
            'trend_composite' => (float) $trend['composite_score'],
            'trend_implied_confidence_pct' => $impliedConfidencePct,
            'calibration_evidence' => [
                'sample_size' => $sampleSize,
                'hit_rate_pct' => $hitRatePct,
                'confidence_bucket' => $bucketLabel,
            ],
            'reliability_weight' => $reliabilityWeight,
            'indicator_groups' => $indicatorGroups,
            'why' => $why,
        ];
    }
}
