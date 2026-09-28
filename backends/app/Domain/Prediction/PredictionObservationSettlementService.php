<?php

namespace App\Domain\Prediction;

use App\Models\PredictionObservation;

final class PredictionObservationSettlementService
{
    public function __construct(
        private readonly PredictionAccuracyEvaluator $evaluator,
        private readonly ?PredictionDecisionNormalizer $normalizer = null,
        private readonly ?PredictionCounterfactualEngine $counterfactuals = null,
    ) {}

    public function settle(PredictionObservation $observation, float $closedExpiryPrice): PredictionObservation
    {
        if ($observation->status !== 'PENDING' || $observation->entry_price === null || $closedExpiryPrice <= 0) {
            return $observation;
        }
        $normalized = ($this->normalizer ?? new PredictionDecisionNormalizer)->normalize([
            'provider' => $observation->provider,
            'provider_version' => $observation->provider_version,
            'direction' => $observation->predicted_direction,
            'confidence' => $observation->confidence,
            'available' => true,
        ]);
        $score = $this->evaluator->evaluate([
            'provider' => $observation->provider, 'direction' => $normalized['decision'] === 'WAIT' ? 'NEUTRAL' : $normalized['decision'],
            'confidence' => $observation->confidence, 'timeframe' => $observation->timeframe,
            'horizon_seconds' => $observation->horizon_seconds,
            'predicted_low' => data_get($observation->metadata, 'predicted_low'),
            'predicted_high' => data_get($observation->metadata, 'predicted_high'),
        ], (float) $observation->entry_price, $closedExpiryPrice, [
            'fee_pct' => data_get($observation->metadata, 'costs.fee_pct', 0),
            'slippage_pct' => data_get($observation->metadata, 'costs.slippage_pct', 0),
            'funding_pct' => data_get($observation->metadata, 'costs.funding_pct', 0),
        ]);
        $metadata = (array) $observation->metadata;
        $metadata['normalized_prediction'] = $normalized;
        $metadata['outcome'] = $score;
        $metadata['counterfactuals'] = ($this->counterfactuals ?? new PredictionCounterfactualEngine)->evaluate(
            (float) $observation->entry_price,
            $closedExpiryPrice,
            [
                'fee_pct' => data_get($observation->metadata, 'costs.fee_pct', 0),
                'slippage_pct' => data_get($observation->metadata, 'costs.slippage_pct', 0),
                'funding_pct' => data_get($observation->metadata, 'costs.funding_pct', 0),
            ],
        );
        $observation->forceFill([
            'expiry_price' => $closedExpiryPrice, 'return_pct' => $score['return_pct'],
            'direction_correct' => $score['direction_correct'], 'status' => 'EVALUATED',
            'metadata' => $metadata,
            'evaluated_at' => now(),
        ])->save();
        return $observation->refresh();
    }
}
