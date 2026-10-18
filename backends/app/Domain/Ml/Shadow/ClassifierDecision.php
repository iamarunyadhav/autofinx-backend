<?php

namespace App\Domain\Ml\Shadow;

/**
 * Pure fee- and funding-aware BUY/SELL/WAIT for a side-classifier prediction.
 *
 *   expected_net_buy  = P(buy)  × avg_win_buy  + (1 − P(buy))  × avg_other_buy  − cost − funding_buy
 *   expected_net_sell = P(sell) × avg_win_sell + (1 − P(sell)) × avg_other_sell − cost + funding_buy
 *
 * (avg_other is the mean signed return when the side's label is false — i.e.
 * the spec's −avg_loss.) A side qualifies only if P >= floor AND its expected
 * net > 0; if both qualify the higher expected net wins; otherwise WAIT.
 * cost_pct comes from QuickTradeCostModel and is charged exactly once.
 */
final class ClassifierDecision
{
    /**
     * @param  array{probability_floor?:?float, buy:array{avg_win_pct:float, avg_other_pct:float}, sell:array{avg_win_pct:float, avg_other_pct:float}}  $params
     * @param  array{cost_pct:float}  $costs
     * @return array{decision:string, decision_status:string, decision_reason:string, expected_net_buy_pct:float, expected_net_sell_pct:float}
     */
    public function decide(float $pBuy, float $pSell, array $params, array $costs, float $fundingBuyPct, ?float $floor = null): array
    {
        $floor ??= (float) ($params['probability_floor'] ?? config('ml.classifier.probability_floor', 0.55));
        $cost = (float) $costs['cost_pct'];
        $netBuy = $pBuy * (float) $params['buy']['avg_win_pct'] + (1 - $pBuy) * (float) $params['buy']['avg_other_pct'] - $cost - $fundingBuyPct;
        $netSell = $pSell * (float) $params['sell']['avg_win_pct'] + (1 - $pSell) * (float) $params['sell']['avg_other_pct'] - $cost + $fundingBuyPct;
        $buy = $pBuy >= $floor && $netBuy > 0;
        $sell = $pSell >= $floor && $netSell > 0;

        [$decision, $status, $reason] = match (true) {
            $buy && (! $sell || $netBuy >= $netSell) => ['BUY', 'VALID_TRADEABLE', 'p_buy_above_floor_and_expected_net_positive'],
            $sell => ['SELL', 'VALID_TRADEABLE', 'p_sell_above_floor_and_expected_net_positive'],
            $pBuy < $floor && $pSell < $floor => ['WAIT', 'WAIT_SIGNAL', 'no_side_probability_above_floor'],
            default => ['WAIT', 'COST_BLOCKED', 'expected_net_not_positive_after_costs'],
        };

        return [
            'decision' => $decision,
            'decision_status' => $status,
            'decision_reason' => $reason,
            'expected_net_buy_pct' => round($netBuy, 8),
            'expected_net_sell_pct' => round($netSell, 8),
        ];
    }
}
