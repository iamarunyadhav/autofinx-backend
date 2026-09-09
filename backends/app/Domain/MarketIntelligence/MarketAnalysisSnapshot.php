<?php

namespace App\Domain\MarketIntelligence;

/**
 * Plain immutable wrap of MultiWindowTrendScoringService::score()'s return
 * array -- a packaging/naming extraction only, no new analysis logic. The
 * named properties expose the fields callers actually need; `raw` keeps the
 * full underlying result available so nothing is lost by wrapping it.
 */
class MarketAnalysisSnapshot
{
    public function __construct(
        public readonly string $trendDirection,
        public readonly float $trendCompositeScore,
        public readonly array $windowBreakdown,
        public readonly float $minComposite,
        public readonly array $raw,
    ) {
    }
}
