<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PredictionAccuracyEvaluator;
use PHPUnit\Framework\TestCase;

class PredictionAccuracyEvaluatorTest extends TestCase
{
    public function test_it_calculates_direction_counterfactual_cost_and_range(): void
    {
        $result = (new PredictionAccuracyEvaluator)->evaluate([
            'provider' => 'external_ml', 'direction' => 'BUY', 'confidence' => 70,
            'predicted_low' => 99, 'predicted_high' => 106,
        ], 100, 105, ['fee_pct' => 0.1, 'slippage_pct' => 0.05]);

        self::assertSame('BUY', $result['actual_direction']);
        self::assertTrue($result['direction_correct']);
        self::assertTrue($result['range_correct']);
        self::assertSame(0.3, $result['cost_pct']);
        self::assertSame(4.7, $result['buy_net_return_pct']);
        self::assertSame(-5.3, $result['sell_net_return_pct']);
    }

    public function test_neutral_market_move_is_not_a_direction_hit_for_buy(): void
    {
        $result = (new PredictionAccuracyEvaluator)->evaluate(['direction' => 'BUY'], 100, 100);

        self::assertSame('NEUTRAL', $result['actual_direction']);
        self::assertFalse($result['direction_correct']);
        self::assertNull($result['range_correct']);
    }
}
