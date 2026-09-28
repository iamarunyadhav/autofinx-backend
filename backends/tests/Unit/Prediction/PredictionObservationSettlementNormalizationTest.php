<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PredictionAccuracyEvaluator;
use App\Domain\Prediction\PredictionDecisionNormalizer;
use App\Domain\Prediction\PredictionObservationSettlementService;
use App\Models\PredictionObservation;
use Tests\TestCase;

final class PredictionObservationSettlementNormalizationTest extends TestCase
{
    public function test_neutral_prediction_is_normalized_to_wait_without_becoming_a_trade_signal(): void
    {
        $observation = new PredictionObservation([
            'symbol' => 'BTCUSDT', 'market_type' => 'crypto', 'provider' => 'external_ml', 'provider_version' => 'v1', 'predicted_direction' => 'NEUTRAL',
            'confidence' => 55, 'timeframe' => '15m', 'horizon_seconds' => 900,
            'entry_price' => 100, 'predicted_at' => now(), 'status' => 'PENDING', 'metadata' => [],
        ]);

        $result = (new PredictionObservationSettlementService(
            new PredictionAccuracyEvaluator(), new PredictionDecisionNormalizer()
        ))->settle($observation, 105);

        self::assertSame('WAIT', data_get($result->metadata, 'normalized_prediction.decision'));
        self::assertFalse((bool) data_get($result->metadata, 'normalized_prediction.execution'));
        self::assertEquals(5.0, data_get($result->metadata, 'outcome.gross_return_pct'));
    }
}
