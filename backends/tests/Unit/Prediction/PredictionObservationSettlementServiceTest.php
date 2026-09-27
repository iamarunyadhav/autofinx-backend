<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PredictionAccuracyEvaluator;
use App\Domain\Prediction\PredictionObservationSettlementService;
use App\Models\PredictionObservation;
use PHPUnit\Framework\TestCase;

final class PredictionObservationSettlementServiceTest extends TestCase
{
    public function test_non_pending_observation_is_not_re_evaluated(): void
    {
        $observation = new PredictionObservation(['status' => 'EVALUATED']);
        $result = (new PredictionObservationSettlementService(new PredictionAccuracyEvaluator()))->settle($observation, 101);
        self::assertSame('EVALUATED', $result->status);
    }
}
