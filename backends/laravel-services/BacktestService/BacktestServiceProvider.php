<?php

namespace Services\BacktestService;

use Illuminate\Support\ServiceProvider;
use Services\BacktestService\Jobs\RunBacktest;

class BacktestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->app['queue']->createPayloadUsing(function (string $connection, string $queue, $payload) {
            return ['meta' => ['service' => 'backtest']];
        });
    }
}
