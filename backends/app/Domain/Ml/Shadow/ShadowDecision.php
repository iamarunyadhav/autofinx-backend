<?php

namespace App\Domain\Ml\Shadow;

/**
 * Pure fee- and funding-aware BUY/SELL/WAIT for a regression shadow prediction.
 *
 * Uses Quick Trade's own cost model output exactly once:
 *   BUY net  =  expected move − cost_pct − funding_buy
 *   SELL net = −expected move − cost_pct + funding_buy   (shorts receive what longs pay)
 * and the same required_move_pct gate Quick Trade applies to its forecast
 * (cost_pct × safety multiplier), applied to the funding-adjusted move.
 */
final class ShadowDecision
{
    /**
     * @param  array{cost_pct:float, required_move_pct:float}  $costs  From QuickTradeCostModel::costs()
     * @return array{predicted_price:float, predicted_low:?float, predicted_high:?float, expected_move_pct:float, buy_net_pct:float, sell_net_pct:float, decision:string, decision_reason:string}
     */
    public function decide(float $currentPrice, float $predictedReturn, ?float $q10, ?float $q90, array $costs, float $fundingBuyPct = 0.0): array
    {
        $movePct = $predictedReturn * 100;
        $costPct = (float) $costs['cost_pct'];
        $requiredPct = (float) $costs['required_move_pct'];
        $buyNet = $movePct - $costPct - $fundingBuyPct;
        $sellNet = -$movePct - $costPct + $fundingBuyPct;

        if ($movePct - $fundingBuyPct >= $requiredPct) {
            [$decision, $reason] = ['BUY', 'expected_move_clears_required_move'];
        } elseif (-$movePct + $fundingBuyPct >= $requiredPct) {
            [$decision, $reason] = ['SELL', 'expected_move_clears_required_move'];
        } else {
            [$decision, $reason] = ['WAIT', 'expected_move_below_required_move'];
        }

        return [
            'predicted_price' => round($currentPrice * (1 + $predictedReturn), 10),
            'predicted_low' => $q10 === null ? null : round($currentPrice * (1 + $q10), 10),
            'predicted_high' => $q90 === null ? null : round($currentPrice * (1 + $q90), 10),
            'expected_move_pct' => round($movePct, 8),
            'buy_net_pct' => round($buyNet, 8),
            'sell_net_pct' => round($sellNet, 8),
            'decision' => $decision,
            'decision_reason' => $reason,
        ];
    }
}
