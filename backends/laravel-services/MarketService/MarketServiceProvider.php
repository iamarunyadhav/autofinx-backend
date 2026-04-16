<?php

namespace Services\MarketService;

use Illuminate\Support\ServiceProvider;
use Services\MarketService\Console\Commands\StartDemoMarket;
use Services\MarketService\Events\MarketTickGenerated;
use Services\StrategyService\Listeners\ForwardMarketTick;

class MarketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->commands([
            StartDemoMarket::class,
        ]);

        $this->app['events']->listen(
            MarketTickGenerated::class,
            ForwardMarketTick::class
        );
    }
}
