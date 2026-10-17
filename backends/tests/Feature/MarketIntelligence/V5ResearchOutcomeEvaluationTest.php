<?php

namespace Tests\Feature\MarketIntelligence;

use App\Models\V5MarketObservation;
use App\Models\V5ShadowPrediction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class V5ResearchOutcomeEvaluationTest extends TestCase
{
    use RefreshDatabase;

    public function test_matured_prediction_is_evaluated_once_from_persisted_closed_observation(): void
    {
        $prediction = V5ShadowPrediction::create([
            'symbol' => 'BTCUSDT',
            'horizon' => '1h',
            'horizon_minutes' => 60,
            'forecast_at' => '2026-10-16 00:00:00',
            'outcome_available_at' => '2026-10-16 01:00:00',
            'direction' => 'UP',
            'reference_price' => 100,
            'probability_calibrated' => false,
            'evidence' => ['horizons' => ['15m' => ['volatility' => 0.01]]],
            'model_version' => 'test-v1',
            'outcome_status' => 'pending',
        ]);
        V5MarketObservation::create([
            'symbol' => 'BTCUSDT', 'timeframe' => '15m',
            'candle_open_ms' => 1792112400000, 'candle_close_ms' => 1792113300000,
            'open' => 100, 'high' => 103, 'low' => 99, 'close' => 102,
            'volume' => 10, 'source' => 'fixture', 'observed_at' => '2026-10-16 01:15:00',
        ]);

        $arguments = ['--as-of' => '2026-10-16T02:00:00Z'];
        $this->artisan('v5:research-evaluate-outcomes', $arguments)->assertSuccessful();
        $this->artisan('v5:research-evaluate-outcomes', $arguments)->assertSuccessful();

        $prediction->refresh();
        $this->assertSame('evaluated', $prediction->outcome_status);
        $this->assertSame('UP', $prediction->actual_direction);
        $this->assertTrue($prediction->correct);
        $this->assertEqualsWithDelta(0.02, $prediction->actual_move, 0.0000001);
        $this->assertEqualsWithDelta(102, $prediction->outcome_price, 0.0000001);
    }

    public function test_matured_prediction_waits_when_no_closed_observation_exists(): void
    {
        $prediction = V5ShadowPrediction::create([
            'symbol' => 'ETHUSDT', 'horizon' => '1h', 'horizon_minutes' => 60,
            'forecast_at' => '2026-10-16 00:00:00', 'outcome_available_at' => '2026-10-16 01:00:00',
            'direction' => 'DOWN', 'reference_price' => 100, 'probability_calibrated' => false,
            'evidence' => [], 'model_version' => 'test-v1', 'outcome_status' => 'pending',
        ]);
        V5MarketObservation::create([
            'symbol' => 'ETHUSDT', 'timeframe' => '15m',
            'candle_open_ms' => 1792116000000, 'candle_close_ms' => 1792116900000,
            'open' => 100, 'high' => 101, 'low' => 98, 'close' => 99,
            'volume' => 10, 'source' => 'late_fixture', 'observed_at' => '2026-10-16 02:15:00',
        ]);

        $this->artisan('v5:research-evaluate-outcomes', ['--as-of' => '2026-10-16T02:00:00Z'])->assertSuccessful();

        $this->assertSame('pending', $prediction->fresh()->outcome_status);
    }
}
