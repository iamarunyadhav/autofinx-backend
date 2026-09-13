<?php

namespace App\Domain\Pepperstone;

/**
 * Simple, explicit spread + commission + slippage cost estimate for a
 * candidate Pepperstone Metals & Energy trade, used by RiskEngine to gate
 * whether a trade's expected profit clears its total execution cost before
 * authorization.
 *
 * Deliberately expressed in raw quote-currency price deltas, not "pips" --
 * metals/energy pip-size conventions vary by broker and this module has no
 * confirmed real Pepperstone contract spec yet (Phase 0 pending a real
 * account, see PepperstoneBrokerAdapter's docblock). Callers pass
 * already-resolved price deltas (e.g. ask-bid for spread, a configured
 * slippage-limit price delta).
 *
 * Formula (no volatility/regime modeling -- intentionally simple):
 *   spread_cost      = spread_price_delta   * lots * contract_size
 *   slippage_cost     = slippage_price_delta * lots * contract_size
 *   commission_cost   = commission_per_lot   * lots
 *   total_cost        = spread_cost + slippage_cost + commission_cost
 *
 *   expected_profit   = expected_move_price  * lots * contract_size
 *
 * A trade is authorized on cost grounds only if expected_profit strictly
 * exceeds total_cost -- a break-even or losing-after-costs trade is never
 * authorized.
 */
class TradingCostCalculator
{
    public function estimateCost(
        float $lots,
        float $contractSize,
        float $spreadPriceDelta,
        float $slippagePriceDelta,
        float $commissionPerLot,
    ): float {
        $spreadCost = max(0.0, $spreadPriceDelta) * $lots * $contractSize;
        $slippageCost = max(0.0, $slippagePriceDelta) * $lots * $contractSize;
        $commissionCost = max(0.0, $commissionPerLot) * $lots;

        return $spreadCost + $slippageCost + $commissionCost;
    }

    public function expectedProfit(float $lots, float $contractSize, float $expectedMovePriceDelta): float
    {
        return $expectedMovePriceDelta * $lots * $contractSize;
    }

    /**
     * True only if expectedProfit strictly exceeds totalCost.
     */
    public function clearsCost(float $expectedProfit, float $totalCost): bool
    {
        return $expectedProfit > $totalCost;
    }
}
