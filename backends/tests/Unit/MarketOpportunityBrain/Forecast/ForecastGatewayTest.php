<?php

namespace Tests\Unit\MarketOpportunityBrain\Forecast;

use App\Domain\MarketOpportunityBrain\Data\Candle;
use App\Domain\MarketOpportunityBrain\Enums\ForecastStatus;
use App\Domain\MarketOpportunityBrain\Forecast\ForecastGateway;
use Tests\TestCase;

/**
 * Deliberately does NOT invoke a real Python subprocess in the normal suite (offline/CI-safe,
 * mirrors MASTER_SPEC.md Phase 3 gate: "Mock/pass with models absent"). A real-subprocess smoke
 * test was run manually once this session -- see TEST_EVIDENCE.md.
 */
class ForecastGatewayTest extends TestCase
{
    private function candle(): Candle
    {
        return new Candle(
            symbol: 'BTCUSDT', timeframe: '15m', openTimeUtc: '2026-10-15T00:00:00Z', closeTimeUtc: '2026-10-15T00:14:59Z',
            observedAtUtc: '2026-10-15T00:14:59Z', open: 100.0, high: 101.0, low: 99.0, close: 100.5, volume: 10.0, isClosed: true, source: 'test',
        );
    }

    /** @test */
    public function disabled_by_default_yields_model_unavailable_without_touching_the_filesystem(): void
    {
        $this->assertFalse(config('market_opportunity_brain.forecast_enabled'));

        $gateway = new ForecastGateway('chronos2');
        $forecast = $gateway->predict('local-research-workspace', 'BTCUSDT', '15m', 4, [$this->candle()], new \DateTimeImmutable('2026-10-15T00:15:00Z'));

        $this->assertSame(ForecastStatus::MODEL_UNAVAILABLE, $forecast->status);
        $this->assertSame('FORECAST_DISABLED_BY_CONFIG', $forecast->reason);
        $this->assertFalse($forecast->toArray()['execution_authorized']);
    }

    /** @test */
    public function enabled_but_unconfigured_python_bin_still_fails_closed(): void
    {
        config(['market_opportunity_brain.forecast_enabled' => true, 'market_opportunity_brain.python_bin' => null]);

        $gateway = new ForecastGateway('chronos2');
        $forecast = $gateway->predict('local-research-workspace', 'BTCUSDT', '15m', 4, [$this->candle()], new \DateTimeImmutable('2026-10-15T00:15:00Z'));

        $this->assertSame(ForecastStatus::MODEL_UNAVAILABLE, $forecast->status);
        $this->assertSame('PYTHON_BIN_NOT_CONFIGURED', $forecast->reason);
    }

    /** @test */
    public function no_closed_candles_yields_unavailable_not_model_unavailable(): void
    {
        config(['market_opportunity_brain.forecast_enabled' => true, 'market_opportunity_brain.python_bin' => '/usr/bin/python3']);

        $gateway = new ForecastGateway('chronos2');
        $forecast = $gateway->predict('local-research-workspace', 'BTCUSDT', '15m', 4, [], new \DateTimeImmutable('2026-10-15T00:15:00Z'));

        $this->assertSame(ForecastStatus::UNAVAILABLE, $forecast->status);
        $this->assertSame('NO_CLOSED_CANDLES_AVAILABLE', $forecast->reason);
    }

    /** @test */
    public function a_nonexistent_python_binary_fails_closed_rather_than_throwing(): void
    {
        config(['market_opportunity_brain.forecast_enabled' => true, 'market_opportunity_brain.python_bin' => 'C:\\definitely\\not\\a\\real\\path\\python.exe']);

        $gateway = new ForecastGateway('chronos2');
        $forecast = $gateway->predict('local-research-workspace', 'BTCUSDT', '15m', 4, [$this->candle()], new \DateTimeImmutable('2026-10-15T00:15:00Z'));

        $this->assertSame(ForecastStatus::MODEL_UNAVAILABLE, $forecast->status);
        $this->assertNotNull($forecast->reason);
    }
}
