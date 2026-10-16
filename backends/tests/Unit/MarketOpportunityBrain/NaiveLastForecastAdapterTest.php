<?php

namespace Tests\Unit\MarketOpportunityBrain;

use App\Domain\MarketOpportunityBrain\Enums\ForecastStatus;
use App\Domain\MarketOpportunityBrain\Fixtures\DeterministicMarketFixtureProvider;
use App\Domain\MarketOpportunityBrain\Forecast\NaiveLastForecastAdapter;
use Tests\TestCase;

/**
 * Covers PHASES_AND_TEST_GATES.md invariants:
 *  - test_forecast_quantiles_ordered_and_probabilities_well_formed
 *  - test_stale_or_unavailable_prediction_yields_wait (model-unavailable path is well-formed; the
 *    actual WAIT decision itself is Phase 4's DecisionPolicy, not this adapter's job)
 */
class NaiveLastForecastAdapterTest extends TestCase
{
    /** @test */
    public function predicts_a_flat_quantile_set_at_the_last_close_with_no_fabricated_direction(): void
    {
        $fixtures = new DeterministicMarketFixtureProvider();
        $asOf = new \DateTimeImmutable('2026-03-15T12:00:00Z');
        $candles = $fixtures->closedCandles('FIXTUREAUSDT', '15m', $asOf, 30);

        $adapter = new NaiveLastForecastAdapter();
        $forecast = $adapter->predict('local-research-workspace', 'FIXTUREAUSDT', '15m', 4, $candles, $asOf);

        $this->assertSame(ForecastStatus::OK, $forecast->status);
        $lastClose = $candles[count($candles) - 1]->close;
        $this->assertSame($lastClose, $forecast->predictionQuantiles['p10']);
        $this->assertSame($lastClose, $forecast->predictionQuantiles['p50']);
        $this->assertSame($lastClose, $forecast->predictionQuantiles['p90']);
        $this->assertNull($forecast->probabilities['up']);
        $this->assertNull($forecast->probabilities['down']);
        $this->assertFalse($forecast->probabilityCalibrated);
        $this->assertFalse($forecast->toArray()['execution_authorized']);
    }

    /** @test */
    public function reports_unavailable_rather_than_fabricating_a_forecast_with_no_candles(): void
    {
        $adapter = new NaiveLastForecastAdapter();
        $asOf = new \DateTimeImmutable('2026-03-15T12:00:00Z');

        $forecast = $adapter->predict('local-research-workspace', 'FIXTUREAUSDT', '15m', 4, [], $asOf);

        $this->assertSame(ForecastStatus::UNAVAILABLE, $forecast->status);
        $this->assertSame('NO_CLOSED_CANDLES_AVAILABLE', $forecast->reason);
        $this->assertNull($forecast->predictionQuantiles['p50']);
    }
}
