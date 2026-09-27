<?php

namespace App\Domain\Prediction;

use App\Models\PredictionObservation;

final class PredictionObservationSettlementService
{
    public function __construct(private readonly PredictionAccuracyEvaluator $evaluator) {}

    public function settle(PredictionObservation $observation, float $closedExpiryPrice): PredictionObservation
    {
        if ($observation->status !== 'PENDING' || $observation->entry_price === null || $closedExpiryPrice <= 0) {
            return $observation;
        }
        $score = $this->evaluator->evaluate([
            'provider' => $observation->provider, 'direction' => $observation->predicted_direction,
            'confidence' => $observation->confidence, 'timeframe' => $observation->timeframe,
            'horizon_seconds' => $observation->horizon_seconds,
        ], (float) $observation->entry_price, $closedExpiryPrice);
        $observation->forceFill([
            'expiry_price' => $closedExpiryPrice, 'return_pct' => $score['return_pct'],
            'direction_correct' => $score['direction_correct'], 'status' => 'EVALUATED',
            'evaluated_at' => now(),
        ])->save();
        return $observation->refresh();
    }
}
