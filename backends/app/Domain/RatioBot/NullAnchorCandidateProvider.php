<?php

namespace App\Domain\RatioBot;

use App\Domain\RatioBot\Contracts\AnchorCandidateProviderInterface;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;

/**
 * Default binding for AnchorCandidateProviderInterface until a real Dynamic
 * Anchor Manager (VWAP / swing-high-low / order-book based) is designed and
 * bound. Always returns zero confidence, so RecoveryReviewService safely
 * falls back to CONTINUE_WAIT rather than ever re-anchoring blindly.
 */
class NullAnchorCandidateProvider implements AnchorCandidateProviderInterface
{
    public function suggestAnchor(
        RatioBotConfig $config,
        RatioBotState $state,
        string $anchorType,
        array $regimeContext = []
    ): array {
        return [
            'candidate_price' => null,
            'confidence' => 0,
            'basis' => 'none',
            'reason' => 'anchor_candidate_provider_not_configured',
        ];
    }
}
