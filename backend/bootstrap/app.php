<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\EventServiceProvider;
use Services\MarketService\MarketServiceProvider;
use Services\StrategyService\StrategyServiceProvider;
use Services\RiskService\RiskServiceProvider;
use Services\ExecutionService\ExecutionServiceProvider;
use Services\BacktestService\BacktestServiceProvider;
use Services\MLService\MLServiceProvider;
use App\Http\Middleware\Cors;
use App\Http\Middleware\RequireApiAccessScope;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        App\Providers\AppServiceProvider::class,
        App\Providers\FortifyServiceProvider::class,
        EventServiceProvider::class,
        MarketServiceProvider::class,
        StrategyServiceProvider::class,
        RiskServiceProvider::class,
        ExecutionServiceProvider::class,
        BacktestServiceProvider::class,
        MLServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(Cors::class);
        $middleware->alias([
            'api.access' => RequireApiAccessScope::class,
            'require.2fa' => RequireTwoFactor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
