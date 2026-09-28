<?php

namespace App\Domain\Prediction;

/** Calculates hypothetical BUY/SELL/WAIT results without placing a trade. */
final class PredictionCounterfactualEngine
{
    /** @param array<string, mixed> $costs */
    public function evaluate(float $entryPrice, float $expiryPrice, array $costs = []): array
    {
        if ($entryPrice <= 0 || $expiryPrice <= 0) {
            return ['status' => 'UNAVAILABLE', 'buy' => null, 'sell' => null, 'wait' => null];
        }

        $fee = max(0, (float) ($costs['fee_pct'] ?? 0));
        $slippage = max(0, (float) ($costs['slippage_pct'] ?? 0));
        $funding = (float) ($costs['funding_pct'] ?? 0);
        $cost = (2 * $fee) + (2 * $slippage) + $funding;
        $move = (($expiryPrice / $entryPrice) - 1) * 100;

        return [
            'status' => 'EVALUATED',
            'cost_pct' => round($cost, 8),
            'buy' => ['gross_return_pct' => round($move, 8), 'net_return_pct' => round($move - $cost, 8), 'profitable' => $move - $cost > 0],
            'sell' => ['gross_return_pct' => round(-$move, 8), 'net_return_pct' => round(-$move - $cost, 8), 'profitable' => -$move - $cost > 0],
            'wait' => ['avoided_loss_pct' => round(max(0, -$move), 8), 'missed_gain_pct' => round(max(0, $move), 8)],
        ];
    }
}
