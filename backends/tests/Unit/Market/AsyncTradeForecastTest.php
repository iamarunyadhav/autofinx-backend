<?php

use App\Domain\Trading\AsyncTradeForecast;
use App\Domain\Trading\QuickTradeForecastCalculator;
use App\Domain\Trading\TradeForecastService;
use App\Jobs\CalculateTradeForecast;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['cache.stores.redis' => ['driver' => 'array', 'serialize' => false]]);
    Cache::forgetDriver('redis');
    Bus::fake();
});

test('forecast requests deduplicate identical inputs and isolate actor symbol and market', function () {
    $service = app(AsyncTradeForecast::class);
    $input = ['symbol' => 'BTCUSDT', 'exchange' => 'binance_futures', 'timeframe' => '1m', 'expiry_seconds' => 60];
    expect($service->request('actor-a', 'forecast', $input)->getStatusCode())->toBe(202);
    $service->request('actor-a', 'forecast', $input);
    $service->request('actor-b', 'forecast', $input);
    $service->request('actor-a', 'forecast', array_replace($input, ['symbol' => 'ETHUSDT']));
    $service->request('actor-a', 'forecast', array_replace($input, ['exchange' => 'binance']));
    Bus::assertDispatchedTimes(CalculateTradeForecast::class, 4);
    Bus::assertDispatched(CalculateTradeForecast::class, fn ($job) => $job->connection === 'redis' && $job->queue === 'predictions');
});

test('worker publishes an exact-input result and request retrieves it without another job', function () {
    $service = app(AsyncTradeForecast::class);
    $input = ['symbol' => 'BTCUSDT', 'exchange' => 'binance_futures', 'timeframe' => '1m', 'expiry_seconds' => 60];
    $service->request('actor-a', 'forecast', $input);
    $job = Bus::dispatched(CalculateTradeForecast::class)->first();
    $calculator = Mockery::mock(QuickTradeForecastCalculator::class);
    $calculator->shouldReceive('forecast')->once()->with($input, Mockery::type(TradeForecastService::class))
        ->andReturn(['forecast' => ['decision_trend' => 'WAIT']]);
    $job->handle($calculator, app(TradeForecastService::class));
    $response = $service->request('actor-a', 'forecast', $input);
    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true)['calculation']['symbol'])->toBe('BTCUSDT')
        ->and($response->getData(true)['calculation']['valid_until'])->toBe($job->requestedAt + 20);
    Bus::assertDispatchedTimes(CalculateTradeForecast::class, 1);
});

test('expired queued inputs are discarded and pending lock is released', function () {
    $cache = Cache::store('redis');
    $lock = $cache->lock('expired:pending', 45);
    $lock->get();
    $calculator = Mockery::mock(QuickTradeForecastCalculator::class);
    $calculator->shouldNotReceive('forecast');
    $job = new CalculateTradeForecast('expired', $lock->owner(), 'forecast', [], time() - 21);
    $job->handle($calculator, app(TradeForecastService::class));
    expect($cache->get('expired'))->toBeNull();
    $replacement = $cache->lock('expired:pending', 45);
    expect($replacement->get())->toBeTrue();
    $replacement->release();
});
