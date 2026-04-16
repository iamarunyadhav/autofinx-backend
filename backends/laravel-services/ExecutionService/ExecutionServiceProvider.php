<?php

namespace Services\ExecutionService;

use Illuminate\Support\ServiceProvider;
use Services\ExecutionService\Adapters\BinanceExchangeAdapter;
use Services\ExecutionService\Adapters\CoinbaseExchangeAdapter;
use Services\ExecutionService\Adapters\ExchangeAdapter;
use Services\ExecutionService\Adapters\ExchangeAdapterFactory;

class ExecutionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BinanceExchangeAdapter::class, function ($app, $params) {
            return new BinanceExchangeAdapter(
                $params['apiKey'] ?? '',
                $params['apiSecret'] ?? '',
                (bool) ($params['isTestnet'] ?? false)
            );
        });

        $this->app->bind(CoinbaseExchangeAdapter::class, function ($app, $params) {
            return new CoinbaseExchangeAdapter(
                $params['apiKey'] ?? '',
                $params['apiSecret'] ?? '',
                (bool) ($params['isSandbox'] ?? false)
            );
        });

        $this->app->singleton(ExchangeAdapterFactory::class, function ($app) {
            return new ExchangeAdapterFactory($app);
        });
    }

    public function boot(): void
    {
        $this->app['queue']->createPayloadUsing(function (string $connection, string $queue, $payload) {
            return ['meta' => ['service' => 'execution']];
        });
    }
}
