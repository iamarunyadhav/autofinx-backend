<?php

namespace Services\StrategyService;

use Illuminate\Support\ServiceProvider;
use Services\StrategyService\Jobs\EvaluateStrategies;

class StrategyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->app['queue']->createPayloadUsing(function (string $connection, string $queue, $payload) {
            return ['meta' => ['service' => 'strategy']];
        });
    }
}
