<?php

namespace App\Domain\MarketIntelligence;

/**
 * Plain value object exposing the real winner-take-all computation already
 * performed by RunRatioBotEvaluationJob::resolveDirectionWithReliabilityWeighting()
 * -- this is an honest extraction of that existing math (via the shared
 * App\Domain\RatioBot\ForecastReliabilityWeighting helper), not a new
 * blended confidence-percentage formula.
 */
class ConfidenceResult
{
    public function __construct(
        public readonly ?string $favoredDirection,
        public readonly float $trendContribution,
        public readonly float $forecastContribution,
        public readonly float $forecastWeight,
        public readonly ?float $hitRatePct,
        public readonly ?int $sampleSize,
        public readonly string $basis = 'statistical_calibration',
        public readonly string $disclaimer = 'This is a calibrated statistical confidence measure derived from this symbol\'s own historical accuracy at this horizon. It is not a prediction or guarantee of future price movement.',
    ) {
    }
}
