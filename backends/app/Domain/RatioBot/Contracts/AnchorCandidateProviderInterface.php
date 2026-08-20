<?php

namespace App\Domain\RatioBot\Contracts;

use App\Models\RatioBotConfig;
use App\Models\RatioBotState;

/**
 * Boundary a future "Dynamic Anchor Manager" must satisfy. StrategyManagerService
 * and RecoveryReviewService only ever READ a candidate price + confidence +
 * rationale from this contract — actual candidate-generation math (VWAP,
 * swing-high/low, order-book imbalance, etc.) is a separate, later design
 * pass and is NOT implemented here.
 */
interface AnchorCandidateProviderInterface
{
    /**
     * @param string $anchorType 'buy_anchor'|'sell_anchor'
     * @param array $regimeContext StrategyManagerService::classifyRegime() output
     * @return array{candidate_price: ?float, confidence: int, basis: string, reason: string}
     */
    public function suggestAnchor(
        RatioBotConfig $config,
        RatioBotState $state,
        string $anchorType,
        array $regimeContext = []
    ): array;
}
