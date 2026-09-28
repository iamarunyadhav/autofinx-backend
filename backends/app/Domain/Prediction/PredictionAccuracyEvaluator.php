<?php

namespace App\Domain\Prediction;

/** Pure, read-only hindsight scoring for one provider prediction. */
final class PredictionAccuracyEvaluator
{
    public function evaluate(array $prediction, float $entryPrice, float $expiryPrice, array $costs = []): array
    {
        $predicted = strtoupper((string) ($prediction['direction'] ?? 'NEUTRAL'));
        $actual = $expiryPrice > $entryPrice ? 'BUY' : ($expiryPrice < $entryPrice ? 'SELL' : 'NEUTRAL');

        $grossReturnPct = (($expiryPrice / $entryPrice) - 1) * 100;
        $feePct = max(0, (float) ($costs['fee_pct'] ?? 0));
        $slippagePct = max(0, (float) ($costs['slippage_pct'] ?? 0));
        $fundingPct = (float) ($costs['funding_pct'] ?? 0);
        $roundTripCostPct = (2 * $feePct) + (2 * $slippagePct) + $fundingPct;
        $predictedLow = isset($prediction['predicted_low']) ? (float) $prediction['predicted_low'] : null;
        $predictedHigh = isset($prediction['predicted_high']) ? (float) $prediction['predicted_high'] : null;

        return [
            'predicted_direction' => $predicted,
            'actual_direction' => $actual,
            'direction_correct' => $predicted === $actual,
            'entry_price' => $entryPrice,
            'expiry_price' => $expiryPrice,
            'return_pct' => round($grossReturnPct, 8),
            'gross_return_pct' => round($grossReturnPct, 8),
            'cost_pct' => round($roundTripCostPct, 8),
            'buy_net_return_pct' => round($grossReturnPct - $roundTripCostPct, 8),
            'sell_net_return_pct' => round(-$grossReturnPct - $roundTripCostPct, 8),
            'wait_result_pct' => 0.0,
            'range_correct' => $predictedLow !== null && $predictedHigh !== null
                ? $expiryPrice >= min($predictedLow, $predictedHigh) && $expiryPrice <= max($predictedLow, $predictedHigh)
                : null,
            'confidence' => $prediction['confidence'] ?? null,
            'provider' => $prediction['provider'] ?? null,
            'timeframe' => $prediction['timeframe'] ?? null,
            'horizon_seconds' => $prediction['horizon_seconds'] ?? null,
        ];
    }
}
