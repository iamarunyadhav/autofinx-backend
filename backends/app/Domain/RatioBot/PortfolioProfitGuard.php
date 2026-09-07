<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotLot;

/**
 * The genuinely missing piece from RecoveryReviewService's per-position-only
 * "never re-anchor/exit at a loss" rule: whether the CYCLE (not just this
 * one stuck position) can absorb a bounded, controlled loss on it. Verified
 * before writing this: RecoveryReviewService has zero references to
 * cycle-level/portfolio-level P&L anywhere -- it only ever compares
 * state->last_buy_price against the current price.
 *
 * This service never weakens the underlying rule on its own -- it only
 * answers "would the cycle still be net-positive after this exit". Callers
 * (RatioBrain) must independently also confirm an unfavorable trend signal
 * before ever acting on `allowed: true`; neither signal is sufficient alone.
 */
class PortfolioProfitGuard
{
    /**
     * @param  float  $candidateLossUsdt  signed or unsigned -- only the
     *                magnitude is used; this is the loss (in quote currency)
     *                closing the stuck position now would realize.
     * @param  float  $unrealizedOtherLotsUsdt  mark-to-market P&L of any
     *                OTHER still-open lot at $currentPrice (0.0 when the
     *                candidate already represents "close everything").
     */
    public function allows(RatioBotConfig $config, float $candidateLossUsdt, float $unrealizedOtherLotsUsdt, float $currentPrice): array
    {
        $realizedCycleProfit = $this->realizedCycleProfit($config);
        $candidateLoss = abs($candidateLossUsdt);
        $requiredBuffer = $config->minimumCycleProfitBuffer($candidateLoss);

        $projected = $realizedCycleProfit + $unrealizedOtherLotsUsdt - $candidateLoss;

        return [
            'allowed' => $projected >= $requiredBuffer,
            'projected_cycle_net' => round($projected, 8),
            'required_buffer' => round($requiredBuffer, 8),
            'realized_cycle_profit' => round($realizedCycleProfit, 8),
            'unrealized_other_lots' => round($unrealizedOtherLotsUsdt, 8),
            'candidate_loss' => round($candidateLoss, 8),
            'current_price' => $currentPrice,
        ];
    }

    /**
     * Real, already-queryable today via RatioBotLot's cycle_number +
     * realized_pnl columns -- no schema change needed for this part
     * (verified before writing this: the spec's own uncertainty about
     * whether "current cycle" is queryable was unfounded).
     */
    private function realizedCycleProfit(RatioBotConfig $config): float
    {
        $cycleNumber = app(RatioBotLotService::class)->currentCycleNumber($config);

        return (float) RatioBotLot::query()
            ->where('ratio_bot_config_id', $config->id)
            ->where('cycle_number', $cycleNumber)
            ->where('status', RatioBotLot::STATUS_CLOSED)
            ->sum('realized_pnl');
    }
}
