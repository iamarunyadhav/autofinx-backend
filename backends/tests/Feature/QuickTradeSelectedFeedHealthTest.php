<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Ops\TradingRuntimeHealthService;
use App\Models\SchedulerRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('selected futures feed recovers independently without trusting other symbols', function () {
    foreach (['quick-trades:settle', 'robot-sessions:evaluate', 'future-trade:tick', 'future-trade:reconcile'] as $command) {
        SchedulerRun::create(['id' => (string) Str::uuid(), 'command' => $command,
            'started_at' => now(), 'finished_at' => now(), 'status' => 'completed']);
    }
    $prices = Mockery::mock(MarketPriceCacheService::class);
    $prices->shouldReceive('getLiveFeedSnapshot')->with('binance_futures', 'BTCUSDT', Mockery::type('int'))
        ->andReturn(['fresh' => true, 'price' => 80000, 'age_seconds' => 0]);
    $prices->shouldReceive('getLiveFeedSnapshot')->with('binance_futures', 'SOLUSDT', Mockery::type('int'))
        ->andReturn(null, ['fresh' => false, 'price' => 100, 'age_seconds' => 71],
            ['fresh' => true, 'price' => 101, 'age_seconds' => 0]);
    $health = new TradingRuntimeHealthService($prices);

    expect($health->snapshot(null, 'BTC/USDT')['overall'])->toBe('green');
    expect($health->snapshot(null, 'SOLUSDT')['future_trade']['live_feed'])->toBe('red');
    expect($health->snapshot(null, 'SOLUSDT')['future_trade']['live_feed'])->toBe('red');
    expect($health->snapshot(null, 'SOLUSDT')['overall'])->toBe('green');
});
