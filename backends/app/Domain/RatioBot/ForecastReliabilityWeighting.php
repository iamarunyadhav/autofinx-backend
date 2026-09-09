<?php

namespace App\Domain\RatioBot;

/**
 * Shared extraction of the forecast-reliability weight-band mapping used by
 * RunRatioBotEvaluationJob::resolveDirectionWithReliabilityWeighting() (see
 * that method's docblock for the full rationale) so any future caller --
 * including App\Domain\MarketIntelligence\ConfidenceEngine -- can reuse the
 * exact same, already-tested thresholds instead of re-deriving them.
 *
 * A missing/null hit rate is treated the same as a proven-unreliable
 * forecast (weight 0.0) -- a forecast only earns weight once it has actually
 * demonstrated better-than-coin-flip accuracy for this specific bot.
 */
class ForecastReliabilityWeighting
{
    public static function weightForHitRate(?float $hitRatePct): float
    {
        return match (true) {
            $hitRatePct === null => 0.0,
            $hitRatePct <= 50.0  => 0.0,
            $hitRatePct <= 60.0  => 0.10,
            $hitRatePct <= 70.0  => 0.20,
            default              => 0.30,
        };
    }
}
