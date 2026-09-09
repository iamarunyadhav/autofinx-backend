<?php

namespace App\Domain\MarketIntelligence;

use App\Domain\RatioBot\MultiWindowTrendScoringService;
use App\Models\RatioBotConfig;

/**
 * Thin facade over MultiWindowTrendScoringService -- packaging/naming
 * extraction only, no new analysis logic and no new decision authority.
 * Depends only on MultiWindowTrendScoringService (no wallet/order/exchange
 * client dependency), so it can be reused by other bot types later without
 * pulling in Ratio-Bot-specific execution machinery.
 */
class MarketAnalysisEngine
{
    public function __construct(
        private readonly MultiWindowTrendScoringService $trendScoring,
    ) {
    }

    public function analyze(RatioBotConfig $config): MarketAnalysisSnapshot
    {
        $trend = $this->trendScoring->score($config);

        return new MarketAnalysisSnapshot(
            trendDirection: $trend['direction'],
            trendCompositeScore: (float) $trend['composite_score'],
            windowBreakdown: $trend['windows'] ?? [],
            minComposite: (float) ($trend['min_composite'] ?? 0.0),
            raw: $trend,
        );
    }
}
