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
