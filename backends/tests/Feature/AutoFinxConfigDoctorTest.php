<?php

use Illuminate\Support\Facades\Artisan;

test('config doctor fails on supervisor and scheduled market runtime conflict', function () {
    config()->set('app.env', 'production');
    config()->set('app.debug', false);
    config()->set('database.redis.client', 'predis');
    config()->set('queue.default', 'redis');
    config()->set('autofinx.runtime.supervisor', true);
    config()->set('autofinx.scheduler.market_consumers', true);
    config()->set('future_trade.schedule.runtime_commands', false);
    config()->set('future_trade.enable_live_trading', false);
    config()->set('future_trade.paper_trading', true);
    config()->set('queue_control.horizon_enabled', false);

    $exit = Artisan::call('autofinx:config-doctor');

    expect($exit)->toBe(2)
        ->and(Artisan::output())->toContain('No scheduler/supervisor market-feed conflict FAIL');
});

test('config doctor fails when horizon is enabled but not installed', function () {
    config()->set('app.env', 'production');
    config()->set('app.debug', false);
    config()->set('database.redis.client', 'predis');
    config()->set('queue.default', 'redis');
    config()->set('autofinx.runtime.supervisor', true);
    config()->set('autofinx.scheduler.market_consumers', false);
    config()->set('future_trade.schedule.runtime_commands', false);
    config()->set('future_trade.enable_live_trading', false);
    config()->set('future_trade.paper_trading', true);
    config()->set('queue_control.horizon_enabled', true);

    $exit = Artisan::call('autofinx:config-doctor');

    expect($exit)->toBe(2)
        ->and(Artisan::output())->toContain('Horizon flag matches installed package FAIL');
});

test('config doctor verifies all catalog queues are covered by worker profiles', function () {
    config()->set('app.env', 'production');
    config()->set('app.debug', false);
    config()->set('database.redis.client', 'predis');
    config()->set('queue.default', 'redis');
    config()->set('autofinx.runtime.supervisor', true);
    config()->set('autofinx.scheduler.market_consumers', false);
    config()->set('future_trade.schedule.runtime_commands', false);
    config()->set('future_trade.enable_live_trading', false);
    config()->set('future_trade.paper_trading', true);
    config()->set('queue_control.horizon_enabled', false);
    config()->set('queue_control.worker_profiles.worker_2_runtime.queues', ['market']);

    $exit = Artisan::call('autofinx:config-doctor');

    expect($exit)->toBe(2)
        ->and(Artisan::output())->toContain('Every configured queue has worker profile FAIL');
});
