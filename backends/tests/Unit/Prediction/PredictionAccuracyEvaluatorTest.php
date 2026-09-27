<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PredictionAccuracyEvaluator;
use PHPUnit\Framework\TestCase;

final class PredictionAccuracyEvaluatorTest extends TestCase
{
    public function test_buy_prediction_is_scored_against_closed_expiry_price(): void
    {
        $result = (new PredictionAccuracyEvaluator())->evaluate([
            'provider' => 'market_structure', 'direction' => 'BUY', 'confidence' => 72,
            'timeframe' => '15m', 'horizon_seconds' => 900,
        ], 100, 101.25);

        self::assertTrue($result['direction_correct']);
        self::assertSame('BUY', $result['actual_direction']);
        self::assertSame(1.25, $result['return_pct']);
    }

    public function test_wait_is_not_counted_as_a_directional_hit(): void
    {
        $result = (new PredictionAccuracyEvaluator())->evaluate([
            'provider' => 'legacy_autofinx', 'direction' => 'WAIT',
        ], 100, 101);

        self::assertFalse($result['direction_correct']);
        self::assertSame('BUY', $result['actual_direction']);
    }
}
