<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Support\Clock\Clock;
use App\Support\Clock\SystemClock;
use App\Domain\Execution\ExecutionEngine;
use App\Domain\Execution\Adapters\BinanceAdapter;
use App\Domain\Execution\Adapters\CoinbaseAdapter;
use App\Domain\Ops\SafeLiveGate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Clock::class, SystemClock::class);
        $this->app->bind(ExecutionEngine::class, function ($app) {
            return new ExecutionEngine(
                $app->make(Clock::class),
                $app->make(BinanceAdapter::class),
                $app->make(CoinbaseAdapter::class),
                $app->make(SafeLiveGate::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $tz = config('app.timezone', 'Asia/Colombo');
        date_default_timezone_set($tz);
    }
}
