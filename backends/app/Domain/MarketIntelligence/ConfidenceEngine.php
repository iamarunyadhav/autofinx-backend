<?php

namespace App\Domain\MarketIntelligence;

use App\Domain\RatioBot\ForecastReliabilityWeighting;
use App\Models\RatioBotConfig;
use App\Models\RatioBotForecastBacktest;

/**
 * Thin facade mirroring the REAL math already in
 * RunRatioBotEvaluationJob::resolveDirectionWithReliabilityWeighting()
 * (trend magnitude x trend weight vs. 1.0 x forecast weight, same
 * tie-favors-trend `>=`) -- an honest extraction of the existing
 * computation via the shared ForecastReliabilityWeighting helper, not a new
 * formula. No new decision authority: nothing in production calls this yet.
 */
class ConfidenceEngine
{
    public function scoreConfidence(MarketAnalysisSnapshot $analysis, RatioBotConfig $config, int $horizonSeconds): ConfidenceResult
    {
        $backtestRow = RatioBotForecastBacktest::where('ratio_bot_config_id', $config->id)
            ->where('horizon_seconds', $horizonSeconds)
            ->latest('computed_at')
            ->first();

        $hitRatePct = $backtestRow?->hit_rate_pct !== null ? (float) $backtestRow->hit_rate_pct : null;
        return $this->scoreEvidence($analysis, $hitRatePct, $backtestRow?->sample_size);
    }

    /** Reuse the weighting math with explicitly scoped calibration evidence. */
    public function scoreEvidence(MarketAnalysisSnapshot $analysis, ?float $hitRatePct, ?int $sampleSize): ConfidenceResult
    {
        $forecastWeight = ForecastReliabilityWeighting::weightForHitRate($hitRatePct);
        $trendWeight = 1.0 - $forecastWeight;

        $trendContribution = abs($analysis->trendCompositeScore) * $trendWeight;
        $forecastContribution = 1.0 * $forecastWeight;

        return new ConfidenceResult(
            favoredDirection: $trendContribution >= $forecastContribution ? 'trend' : 'forecast',
            trendContribution: $trendContribution,
            forecastContribution: $forecastContribution,
            forecastWeight: $forecastWeight,
            hitRatePct: $hitRatePct,
            sampleSize: $sampleSize,
        );
    }
}
