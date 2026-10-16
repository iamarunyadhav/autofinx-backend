<?php

namespace Tests\Unit\MarketIntelligence\V5;

use App\Domain\MarketIntelligence\V5\ShadowOutcomeEvaluator;
use App\Domain\MarketIntelligence\V5\ShadowPrediction;
use InvalidArgumentException;
use Tests\TestCase;

class ShadowPredictionTest extends TestCase
{
    public function test_prediction_is_research_only_and_validates_direction(): void
    {
        $prediction = new ShadowPrediction('BTCUSDT', '24h', 1440,
            '2026-10-16T00:00:00Z', '2026-10-17T00:00:00Z', 'UP', .02,
            null, false, ['trend' => 'source'], 'v5-research-1');

        $this->assertFalse($prediction->toArray()['execution_authorized']);
    }

    public function test_calibrated_probabilities_must_exist_and_sum_is_bounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ShadowPrediction('ETHUSDT', '1h', 60, '2026-10-16T00:00:00Z',
            '2026-10-16T01:00:00Z', 'DOWN', null, ['up' => .7, 'down' => .5, 'sideways' => 0], true, [], 'v5');
    }

    public function test_outcome_cannot_be_evaluated_before_horizon(): void
    {
        $p = new ShadowPrediction('SOLUSDT', '15m', 15, '2026-10-16T00:00:00Z',
            '2026-10-16T00:15:00Z', 'SIDEWAYS', null, null, false, [], 'v5');
        $this->expectException(InvalidArgumentException::class);
        (new ShadowOutcomeEvaluator())->evaluate($p, '2026-10-16T00:10:00Z', .01);
    }

    public function test_outcome_direction_is_evaluated_after_horizon(): void
    {
        $p = new ShadowPrediction('SOLUSDT', '15m', 15, '2026-10-16T00:00:00Z',
            '2026-10-16T00:15:00Z', 'UP', null, null, false, [], 'v5');
        $result = (new ShadowOutcomeEvaluator())->evaluate($p, '2026-10-16T00:16:00Z', .01);
        $this->assertTrue($result['correct']);
        $this->assertFalse($result['execution_authorized']);
    }
}
