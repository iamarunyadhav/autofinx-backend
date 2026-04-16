<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Events\StrategySignalEmitted;
use App\Listeners\HandleStrategySignal;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        StrategySignalEmitted::class => [
            HandleStrategySignal::class,
        ],
    ];
}
