<?php

namespace App\Domain\RatioBot;

use App\Domain\RatioBot\Contracts\AnchorCandidateProviderInterface;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;

/**
 * Reviews a bot in RECOVERY_MONITORING / CASH_RECOVERY_MONITORING once its
 * recovery_review_at timer is due. Structurally CANNOT execute a trade: the
 * return shape below has no side/price/qty field, and never recommends
 * re-anchoring a stuck LONG position at a loss (PAUSE instead) — a losing
 * position is never laundered into a "fresh" anchor.
 */
class RecoveryReviewService
{
    public const OUTCOME_KEEP_EXISTING_ANCHOR = 'KEEP_EXISTING_ANCHOR';
    public const OUTCOME_REANCHOR_STRATEGY    = 'REANCHOR_STRATEGY';
    public const OUTCOME_CONTINUE_WAIT        = 'CONTINUE_WAIT';
    public const OUTCOME_PAUSE                = 'PAUSE';

    private const MIN_CANDIDATE_CONFIDENCE = 50;

    public function __construct(
        private readonly StrategyManagerService $strategyManager,
        private readonly AnchorCandidateProviderInterface $anchorCandidates,
    ) {
    }

    /**
     * @param string $recoveryMode 'position_stuck'|'cash_stuck'
     * @return array{
     *     outcome: string,
     *     reason: string,
     *     regime: ?string,
     *     distance_from_cost_basis_ratio: float,
     *     suggested_anchor: ?array,
     * }
     */
    public function review(RatioBotConfig $config, RatioBotState $state, float $currentPrice, string $recoveryMode): array
    {
        $costBasis = (float) ($state->last_buy_price ?? 0);
        $distance = $costBasis > 0 ? ($currentPrice / $costBasis) - 1 : 0.0;

        $analysis = $this->strategyManager->analyze($config, $state, $currentPrice);
        $regime = (string) ($analysis['regime'] ?? StrategyManagerService::REGIME_UNCERTAIN);

        // A stuck LONG position sitting below its cost basis is never
        // re-anchored — that would silently launder an unrealized loss into
        // a "fresh" anchor. Pause for operator review instead.
        if ($recoveryMode === 'position_stuck' && $distance < 0) {
            return $this->result(self::OUTCOME_PAUSE, 'position_stuck_below_cost_basis: never re-anchor at a loss', $regime, $distance, null);
        }

        if (in_array($regime, [StrategyManagerService::REGIME_HIGH_VOLATILITY, StrategyManagerService::REGIME_LOW_LIQUIDITY], true)) {
            return $this->result(self::OUTCOME_CONTINUE_WAIT, "regime={$regime}: market conditions too unstable to reassess", $regime, $distance, null);
        }

        $anchorType = $recoveryMode === 'cash_stuck' ? 'sell_anchor' : 'buy_anchor';
        $regimeStillFavorsExistingAnchor = $recoveryMode === 'cash_stuck'
            ? in_array($regime, [StrategyManagerService::REGIME_STRONG_DOWNTREND, StrategyManagerService::REGIME_WEAK_DOWNTREND], true)
            : in_array($regime, [StrategyManagerService::REGIME_STRONG_UPTREND, StrategyManagerService::REGIME_WEAK_UPTREND], true);

        if ($regimeStillFavorsExistingAnchor) {
            return $this->result(self::OUTCOME_KEEP_EXISTING_ANCHOR, "regime={$regime}: still favors the existing anchor", $regime, $distance, null);
        }

        $candidate = $this->anchorCandidates->suggestAnchor($config, $state, $anchorType, ['regime' => $regime]);
        $confidence = (int) ($candidate['confidence'] ?? 0);
        $candidatePrice = $candidate['candidate_price'] ?? null;

        if ($candidatePrice === null || $confidence < self::MIN_CANDIDATE_CONFIDENCE) {
            return $this->result(self::OUTCOME_CONTINUE_WAIT, "no confident anchor candidate available (confidence={$confidence})", $regime, $distance, $candidate);
        }

        return $this->result(self::OUTCOME_REANCHOR_STRATEGY, "regime={$regime} flipped; confident anchor candidate found", $regime, $distance, $candidate);
    }

    private function result(string $outcome, string $reason, string $regime, float $distance, ?array $suggestedAnchor): array
    {
        return [
            'outcome' => $outcome,
            'reason' => $reason,
            'regime' => $regime,
            'distance_from_cost_basis_ratio' => round($distance, 6),
            'suggested_anchor' => $suggestedAnchor,
        ];
    }
}
