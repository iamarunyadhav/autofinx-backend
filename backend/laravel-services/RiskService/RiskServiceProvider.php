<?php

namespace Services\RiskService;

use Illuminate\Support\ServiceProvider;

class RiskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->app['queue']->createPayloadUsing(function (string $connection, string $queue, $payload) {
            return ['meta' => ['service' => 'risk']];
        });
    }
}
