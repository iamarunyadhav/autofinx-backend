<?php

namespace App\Domain\Prediction;

/** Pure, read-only hindsight scoring for one provider prediction. */
final class PredictionAccuracyEvaluator
{
    public function evaluate(array $prediction, float $entryPrice, float $expiryPrice): array
    {
        $predicted = strtoupper((string) ($prediction['direction'] ?? 'NEUTRAL'));
        $actual = $expiryPrice > $entryPrice ? 'BUY' : ($expiryPrice < $entryPrice ? 'SELL' : 'NEUTRAL');

        return [
            'predicted_direction' => $predicted,
            'actual_direction' => $actual,
            'direction_correct' => $predicted === $actual,
            'entry_price' => $entryPrice,
            'expiry_price' => $expiryPrice,
            'return_pct' => round((($expiryPrice / $entryPrice) - 1) * 100, 8),
            'confidence' => $prediction['confidence'] ?? null,
            'provider' => $prediction['provider'] ?? null,
            'timeframe' => $prediction['timeframe'] ?? null,
            'horizon_seconds' => $prediction['horizon_seconds'] ?? null,
        ];
    }
}
