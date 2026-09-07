<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotState;

/**
 * Thin orchestration facade -- calls existing, already-tested services in a
 * documented sequence and adds exactly four new components (PortfolioProfitGuard,
 * DynamicObservationWindow, ScoredSellCandidateSelector, SpotEmergencyGuard;
 * see each class's own docblock). Does not reimplement anchor logic, lot
 * management, or the recovery review's core rules -- every outcome not
 * explicitly upgraded here (KEEP_EXISTING_ANCHOR, REANCHOR_STRATEGY,
 * CONTINUE_WAIT, or simply not being in recovery monitoring at all) is left
 * to the existing pipeline to act on exactly as it already does; this
 * facade only logs those for visibility.
 *
 * Every evaluate() call persists one RatioBrainDecisionLog row regardless of
 * shadow mode -- shadow mode only controls whether the caller (see
 * RatioBotRealtimeEngine) is allowed to act on the recommendation, never
 * whether it gets computed or logged.
 */
class RatioBrain
{
    public const ACTION_NONE = 'NONE';
    public const ACTION_EMERGENCY_ACTIVE = 'EMERGENCY_ACTIVE';
    public const ACTION_RECOVERY_OBSERVED = 'RECOVERY_OBSERVED';
    public const ACTION_HOLD_RECOVERY = 'HOLD_RECOVERY';
    public const ACTION_CONTROLLED_LOSS_EXIT = 'CONTROLLED_LOSS_EXIT';

    public function __construct(
        private readonly RecoveryReviewService $recoveryReview,
        private readonly MultiWindowTrendScoringService $trend,
        private readonly PortfolioProfitGuard $profitGuard,
        private readonly DynamicObservationWindow $observationWindow,
        private readonly ScoredSellCandidateSelector $sellSelector,
        private readonly SpotEmergencyGuard $emergencyGuard,
        private readonly RatioBotLotService $lots,
        private readonly RatioBrainDecisionLogger $logger,
    ) {
    }

    public function evaluate(RatioBotConfig $config, RatioBotState $state, float $currentPrice): array
    {
        $shadowMode = $config->ratioBrainShadowMode();

        $emergency = $config->marketType() === 'spot'
            ? $this->emergencyGuard->assess($config, $currentPrice)
            : ['is_emergency' => false, 'reason' => 'not applicable to futures -- the existing Liquidation Guard already covers leveraged risk'];

        $trendScore = $this->trend->score($config);
        $sellCandidates = $this->currentSellCandidates($config, $currentPrice);

        $context = [
            'emergency' => $emergency,
            'trend_score' => $trendScore,
            'sell_candidates' => $sellCandidates,
        ];

        if (! $state->isRecoveryMonitoring()) {
            $action = $emergency['is_emergency'] ? self::ACTION_EMERGENCY_ACTIVE : self::ACTION_NONE;
            $reason = $emergency['is_emergency']
                ? 'spot emergency guard active: ' . $emergency['reason']
                : 'not in recovery monitoring -- delegating to the existing pipeline';

            return $this->finish($action, $reason, $config, $state, $currentPrice, $shadowMode, $context);
        }

        // Still in recovery monitoring: an emergency condition means "don't
        // wait for the normal timer, re-review right now" -- this call is
        // unconditional (not timer-gated) precisely so RatioBrain's shadow
        // view is always fresh regardless of what the existing timer says.
        $recoveryMode = $state->position_state === RatioBotState::STATE_CASH_RECOVERY_MONITORING ? 'cash_stuck' : 'position_stuck';
        $review = $this->recoveryReview->review($config, $state, $currentPrice, $recoveryMode);
        $observationWindowResult = $this->observationWindow->resolveWindowMinutes($config, $state, $trendScore);
        $context['recovery_review'] = $review;
        $context['observation_window'] = $observationWindowResult;

        if ($review['outcome'] !== RecoveryReviewService::OUTCOME_PAUSE || $recoveryMode !== 'position_stuck') {
            $reason = ($emergency['is_emergency'] ? 'emergency active; ' : '') . 'recovery review outcome=' . $review['outcome'] . ' -- existing pipeline handles this outcome unchanged';

            return $this->finish(self::ACTION_RECOVERY_OBSERVED, $reason, $config, $state, $currentPrice, $shadowMode, $context);
        }

        // PAUSE on a stuck long, at a loss -- the one case this facade adds
        // a narrow, additional, portfolio-aware exception to. Requires BOTH
        // the portfolio guard AND an independent unfavorable-trend signal to
        // agree -- neither is sufficient alone, and the underlying
        // never-re-anchor-at-a-loss rule inside RecoveryReviewService itself
        // is never modified or bypassed.
        [$candidateLossUsdt, $lotIds] = $this->candidateCloseAllLoss($config, $state, $currentPrice);
        $guardResult = $this->profitGuard->allows($config, $candidateLossUsdt, 0.0, $currentPrice);
        $trendUnfavorable = $trendScore['direction'] === 'bearish';

        $context['portfolio_guard'] = $guardResult;
        $context['trend_unfavorable'] = $trendUnfavorable;
        $context['candidate_lot_ids'] = $lotIds;
        $context['candidate_close_qty_source'] = $lotIds === [] ? 'anchor' : 'lots';

        if ($guardResult['allowed'] && $trendUnfavorable) {
            $reason = 'portfolio guard allows a bounded loss on this stuck position and the multi-window trend is bearish -- cycle stays net-positive after exit';

            return $this->finish(self::ACTION_CONTROLLED_LOSS_EXIT, $reason, $config, $state, $currentPrice, $shadowMode, $context);
        }

        $reason = sprintf(
            'position_stuck_below_cost_basis: guard_allowed=%s trend_unfavorable=%s',
            $guardResult['allowed'] ? 'true' : 'false',
            $trendUnfavorable ? 'true' : 'false',
        );

        return $this->finish(self::ACTION_HOLD_RECOVERY, $reason, $config, $state, $currentPrice, $shadowMode, $context);
    }

    /**
     * Informational only in this pass -- computed and logged every
     * evaluation so shadow-mode comparison has real ranking data to review,
     * but does not yet change which lot the real profitableFifoSellPlan()
     * -driven sell execution closes first (see ScoredSellCandidateSelector's
     * docblock and this project's established shadow-first rollout
     * discipline). Flip this only after shadow-mode comparison confirms the
     * ranking consistently agrees with acceptable outcomes.
     */
    private function currentSellCandidates(RatioBotConfig $config, float $currentPrice): array
    {
        if (! (bool) $config->v2_enabled) {
            return [];
        }

        $minNetProfitRatio = max(0.0, (float) ($config->min_net_profit_bps ?? 0) / 10000);
        $plan = $this->lots->profitableFifoSellPlan($config, $currentPrice, $minNetProfitRatio, 0.0);

        if ($plan['lot_ids'] === []) {
            return [];
        }

        return $this->sellSelector->rank($config, $plan['lot_ids'], $currentPrice);
    }

    /**
     * "Closing everything now" -- the correct candidate-loss measure for a
     * position-stuck recovery-monitoring bot, since by definition the WHOLE
     * position is what's stuck, not one lot among several healthy ones.
     * Falls back to the anchor-based single-position case
     * (state->last_buy_price/last_buy_quantity) for bots that never opted
     * into V2 lot-based accounting -- confirmed via code reading that lots
     * are not guaranteed to exist even for a recovery-monitoring bot.
     *
     * @return array{0: float, 1: array<int, string>}
     */
    private function candidateCloseAllLoss(RatioBotConfig $config, RatioBotState $state, float $currentPrice): array
    {
        $openLots = $this->lots->openLots($config);
        if ($openLots->isNotEmpty()) {
            $loss = 0.0;
            $lotIds = [];
            foreach ($openLots as $lot) {
                $buyPrice = (float) $lot->buy_price;
                $remaining = (float) $lot->remaining_qty;
                $loss += ($currentPrice - $buyPrice) * $remaining;
                $lotIds[] = (string) $lot->id;
            }

            return [$loss, $lotIds];
        }

        $buyPrice = (float) ($state->last_buy_price ?? 0);
        $qty = (float) ($state->last_buy_quantity ?? 0);

        return [($currentPrice - $buyPrice) * $qty, []];
    }

    private function finish(string $action, string $reason, RatioBotConfig $config, RatioBotState $state, float $currentPrice, bool $shadowMode, array $breakdown): array
    {
        $result = [
            'action' => $action,
            'reason' => $reason,
            'shadow_mode' => $shadowMode,
            'market_price' => $currentPrice,
            'position_state' => $state->position_state,
            'component_breakdown' => $breakdown,
        ];

        $log = $this->logger->log($config, $result);
        $result['decision_log_id'] = (string) $log->id;

        return $result;
    }
}
