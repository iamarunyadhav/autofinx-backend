<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Ops\FeatureFlagService;
use App\Domain\Trading\QuickTradeForecastCalculator;
use App\Domain\Trading\RuntimeSafetySettingsService;
use App\Domain\Trading\TradeForecastService;

afterEach(function () {
    Mockery::close();
});

it('uses the requested spot namespace and exposes only canonical live-feed telemetry', function () {
    $prices = Mockery::mock(MarketPriceCacheService::class);
    $prices->shouldReceive('getLiveFeedSnapshot')
        ->once()
        ->with('binance', 'ETHUSDT', 3)
        ->andReturn([
            'fresh' => true,
            'price' => 2500.25,
            'age_seconds' => 0,
            'event_lag_ms' => 84,
            'max_age_seconds' => 3,
            'max_event_lag_ms' => 10000,
            'market_type' => 'spot',
        ]);
    app()->instance(MarketPriceCacheService::class, $prices);

    $safety = Mockery::mock(RuntimeSafetySettingsService::class);
    $safety->shouldReceive('liveFeedMaxAgeSeconds')->once()->with(null)->andReturn(3);
    app()->instance(RuntimeSafetySettingsService::class, $safety);

    $flags = Mockery::mock(FeatureFlagService::class);
    $flags->shouldReceive('enabled')->once()->with('unified_forecast.quick_trade_shadow', true)->andReturnFalse();
    $flags->shouldReceive('enabled')->once()->with('unified_forecast.quick_trade_live', false)->andReturnFalse();
    app()->instance(FeatureFlagService::class, $flags);

    $forecast = Mockery::mock(TradeForecastService::class);
    $forecast->shouldReceive('forecast')->once()->andReturn([
        'available' => true,
        'reference_price' => 2500.25,
        'reference_price_source' => 'fresh_canonical_quote',
        'decision_trend' => 'BUY',
    ]);
    $forecast->shouldReceive('netEdge')->once()->andReturn(['tradeable' => true]);

    $result = app(QuickTradeForecastCalculator::class)->forecast([
        'candles' => [['open' => 1, 'high' => 1, 'low' => 1, 'close' => 1]],
        'symbol' => 'ETHUSDT',
        'exchange' => 'binance',
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'stake_amount' => 10,
    ], $forecast);

    expect($result['forecast']['live_feed'])->toBe([
        'fresh' => true,
        'age_seconds' => 0,
        'event_lag_ms' => 84,
        'max_age_seconds' => 3,
        'max_event_lag_ms' => 10000,
        'market_type' => 'spot',
    ]);
});
