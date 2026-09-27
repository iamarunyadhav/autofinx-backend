<?php

use App\Domain\MarketIntelligence\MarketAnalysisEngine;
use App\Domain\MarketIntelligence\MarketAnalysisSnapshot;
use App\Domain\MarketIntelligence\UnifiedForecastEngine;
use App\Domain\Trading\QuickTradeForecastCalculator;
use App\Domain\Trading\TradeForecastService;
use Carbon\CarbonImmutable;

it('excludes the forming candle from comparison and exposes honest uncalibrated helper weights', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-27T10:00:30Z'));
    config(['quick_trade.closed_candles_only' => true]);
    $closed = [];
    for ($i = 10; $i > 0; $i--) {
        $closed[] = ['timestamp' => CarbonImmutable::now()->startOfMinute()->subMinutes($i)->getTimestampMs(),
            'open' => 100, 'high' => 102, 'low' => 99, 'close' => 101];
    }
    $forming = ['timestamp' => CarbonImmutable::now()->startOfMinute()->getTimestampMs(),
        'open' => 101, 'high' => 999, 'low' => 1, 'close' => 999];
    $forecast = Mockery::mock(TradeForecastService::class);
    $forecast->shouldReceive('forecast')->once()->withArgs(function ($candles, $tf, $horizon, $decision, $vol, $feed, $symbol, $reference, $timing) use ($closed) {
        return $candles === $closed && $reference === null && $timing['forming_bar_dropped'] === true;
    })->andReturn(['bias' => 'BUY']);
    $unified = Mockery::mock(UnifiedForecastEngine::class);
    $unified->shouldReceive('evaluate')->once()->with('robot_trade', 'binance_futures:BTCUSDT:1m', 'binance_futures', 'BTCUSDT', '1m', 60)
        ->andReturn(['direction' => 'BUY', 'calibrated' => false,
            'calibration_evidence' => ['hit_rate_pct' => 100, 'sample_size' => 2]]);
    app()->instance(UnifiedForecastEngine::class, $unified);
    $analysis = Mockery::mock(MarketAnalysisEngine::class);
    $analysis->shouldReceive('analyzeFor')->once()->with('binance_futures', 'BTCUSDT', '1m')
        ->andReturn(new MarketAnalysisSnapshot('bullish', 0.5, [], 0.15, []));
    app()->instance(MarketAnalysisEngine::class, $analysis);
    try {
        $result = app(QuickTradeForecastCalculator::class)->comparison([
            'bot_type' => 'robot_trade', 'exchange' => 'binance_futures', 'symbol' => 'BTCUSDT',
            'timeframe' => '1m', 'expiry_seconds' => 60, 'candles' => [...$closed, $forming],
        ], $forecast);
        expect($result['helpers']['confidence']->hitRatePct)->toBeNull()
            ->and($result['helpers']['confidence']->forecastWeight)->toBe(0.0)
            ->and($result['helpers']['confidence']->trendContribution)->toBe(0.5)
            ->and($result['execution_source'])->toBe('unchanged_existing_engine');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('keeps robot report signals and forecast prices independent of forming ticks and runtime decisions', function () {
    $this->travelTo(now()->startOfMinute()->addSeconds(30));
    config(['quick_trade.closed_candles_only' => true]);
    $closed = [];
    for ($i = 10; $i > 0; $i--) {
        $closed[] = ['timestamp' => now()->startOfMinute()->subMinutes($i)->getTimestampMs(),
            'open' => 100, 'high' => 102, 'low' => 99, 'close' => 101];
    }
    $forming = ['timestamp' => now()->startOfMinute()->getTimestampMs(),
        'open' => 101, 'high' => 999, 'low' => 1, 'close' => 999];
    $signals = Mockery::mock(\App\Domain\Trading\SignalPreviewService::class);
    $signals->shouldReceive('preview')->once()->with($closed, '1m', ['RSI'])
        ->andReturn(['direction' => 'BUY', 'confidence' => 60]);
    app()->instance(\App\Domain\Trading\SignalPreviewService::class, $signals);
    $forecast = Mockery::mock(TradeForecastService::class);
    $forecast->shouldReceive('decisionFromSignalPreview')->once()
        ->with(['direction' => 'BUY', 'confidence' => 60])->andReturn(['action' => 'BUY']);
    $forecast->shouldReceive('forecast')->once()->withArgs(function ($candles, $tf, $horizon, $decision, $vol, $feed, $symbol, $reference, $timing) use ($closed) {
        return $candles === $closed && $decision === ['action' => 'BUY'] && $vol === null
            && $reference === null && $timing['forming_bar_dropped'];
    })->andReturn(['available' => true]);
    app()->instance(TradeForecastService::class, $forecast);
    $session = new \App\Models\RobotSession(['symbol' => 'BTCUSDT', 'timeframe' => '1m',
        'expiry_seconds' => 60, 'indicator_set' => ['RSI']]);
    $method = new ReflectionMethod(\App\Domain\Trading\RobotSessionService::class, 'robotForecast');
    $result = $method->invoke(app(\App\Domain\Trading\RobotSessionService::class), $session,
        [...$closed, $forming], 999.0, ['action' => 'SELL'], ['atr_pct' => 99]);
    expect($result['available'])->toBeTrue();
});
