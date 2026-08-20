<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\DynamicThresholdService;
use App\Domain\RatioBot\IndicatorService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicThresholdServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_widens_thresholds_when_dynamic_thresholds_are_enabled_in_high_volatility(): void
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'enabled' => true,
            'market_type' => 'spot',
            'timeframe' => '1m',
            'sell_threshold_ratio' => 0.01,
            'buy_threshold_ratio' => 0.01,
            'strategy_params' => [
                'use_dynamic_thresholds' => true,
            ],
        ]);

        $this->seedCandles($config, highVolatility: true);

        $indicatorSnapshot = app(IndicatorService::class)->snapshot($config, 112.0);
        $dynamic = app(DynamicThresholdService::class)->resolve($config, 112.0, $indicatorSnapshot);

        $this->assertTrue($dynamic['enabled']);
        $this->assertGreaterThan(0.01, $dynamic['sell_threshold_ratio']);
        $this->assertGreaterThan(0.01, abs($dynamic['buy_threshold_ratio']));
        $this->assertGreaterThan(0, $dynamic['pullback_ratio']);
        $this->assertContains($dynamic['volatility_level'], ['HIGH', 'EXTREME']);
    }

    /** @test */
    public function it_preserves_manual_thresholds_when_dynamic_thresholds_are_disabled(): void
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'ETHUSDT',
            'enabled' => true,
            'market_type' => 'spot',
            'timeframe' => '1m',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.015,
            'strategy_params' => [
                'use_dynamic_thresholds' => false,
            ],
        ]);

        $this->seedCandles($config, highVolatility: false);

        $indicatorSnapshot = app(IndicatorService::class)->snapshot($config, 101.0);
        $dynamic = app(DynamicThresholdService::class)->resolve($config, 101.0, $indicatorSnapshot);

        $this->assertFalse($dynamic['enabled']);
        $this->assertEqualsWithDelta(0.02, $dynamic['sell_threshold_ratio'], 0.000001);
        $this->assertEqualsWithDelta(0.015, abs($dynamic['buy_threshold_ratio']), 0.000001);
    }

    /** @test */
    public function regime_context_is_only_applied_when_explicitly_passed(): void
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'ADAUSDT',
            'enabled' => true,
            'market_type' => 'spot',
            'timeframe' => '1m',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.02,
            'strategy_params' => ['use_dynamic_thresholds' => true],
        ]);
        $this->seedCandles($config, highVolatility: false);
        $indicatorSnapshot = app(IndicatorService::class)->snapshot($config, 101.0);
        $service = app(DynamicThresholdService::class);

        // Regression pin: byte-identical output to the pre-V2, 3-arg call
        // site (RatioBotRealtimeEngine) when no regime context is passed.
        $withoutRegime = $service->resolve($config, 101.0, $indicatorSnapshot);
        $withEmptyRegime = $service->resolve($config, 101.0, $indicatorSnapshot, []);
        $this->assertSame($withoutRegime, $withEmptyRegime);
        $this->assertNull($withoutRegime['regime']);

        $withStrongDowntrendRegime = $service->resolve($config, 101.0, $indicatorSnapshot, ['regime' => 'STRONG_DOWNTREND']);
        $this->assertSame('STRONG_DOWNTREND', $withStrongDowntrendRegime['regime']);
        // STRONG_DOWNTREND applies a 1.10x regime factor on top of the base
        // dynamic factor, so the effective sell threshold must widen further.
        $this->assertGreaterThanOrEqual($withoutRegime['sell_threshold_ratio'], $withStrongDowntrendRegime['sell_threshold_ratio']);
    }

    /** @test */
    public function resolve_output_includes_reason_and_bounds_keys(): void
    {
        $config = RatioBotConfig::create([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'SOLUSDT',
            'enabled' => true,
            'market_type' => 'spot',
            'timeframe' => '1m',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.02,
            'strategy_params' => ['use_dynamic_thresholds' => true],
        ]);
        $this->seedCandles($config, highVolatility: true);
        $indicatorSnapshot = app(IndicatorService::class)->snapshot($config, 112.0);

        $dynamic = app(DynamicThresholdService::class)->resolve($config, 112.0, $indicatorSnapshot);

        $this->assertIsString($dynamic['reason']);
        $this->assertArrayHasKey('base', $dynamic['sell_threshold_bounds']);
        $this->assertArrayHasKey('min', $dynamic['sell_threshold_bounds']);
        $this->assertArrayHasKey('max', $dynamic['sell_threshold_bounds']);
        $this->assertArrayHasKey('effective', $dynamic['sell_threshold_bounds']);
        $this->assertEqualsWithDelta($dynamic['sell_threshold_ratio'], $dynamic['sell_threshold_bounds']['effective'], 0.000001);
    }

    private function seedCandles(RatioBotConfig $config, bool $highVolatility): void
    {
        $price = 100.0;
        for ($i = 0; $i < 60; $i++) {
            $swing = $highVolatility ? (($i % 2 === 0) ? 0.035 : -0.028) : (($i % 2 === 0) ? 0.004 : -0.003);
            $open = $price;
            $close = $price * (1 + $swing);
            $high = max($open, $close) * ($highVolatility ? 1.012 : 1.003);
            $low = min($open, $close) * ($highVolatility ? 0.988 : 0.997);
            $volume = $highVolatility ? 140 + ($i * 3) : 90 + $i;

            MarketOhlc::create([
                'exchange' => $config->exchangeKey(),
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => $config->timeFrame(),
                'ts' => now()->subMinutes(60 - $i),
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
            ]);

            $price = $close;
        }
    }
}
