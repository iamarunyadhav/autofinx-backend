<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Support\Clock\Clock;
use App\Support\Clock\SystemClock;
use App\Domain\Execution\ExecutionEngine;
use App\Domain\Execution\Adapters\BinanceAdapter;
use App\Domain\Execution\Adapters\CoinbaseAdapter;
use App\Domain\Ops\SafeLiveGate;
use App\Domain\RatioBot\Contracts\AnchorCandidateProviderInterface;
use App\Domain\RatioBot\NullAnchorCandidateProvider;
use App\Domain\AuditResearch\ServerDiagnostics\SshExecutor;
use App\Domain\AuditResearch\ServerDiagnostics\PhpseclibSshExecutor;
use App\Domain\Prediction\PredictionObservatory;
use App\Domain\Prediction\Providers\ExternalMlPredictionProvider;
use App\Domain\Prediction\Providers\LegacyAutoFinXPredictionProvider;
use App\Domain\Prediction\Providers\MarketStructurePredictionProvider;
use App\Domain\Prediction\Providers\PineStrategyPredictionProvider;

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

        // Real Anchor Manager (VWAP/swing-high-low/etc.) is a separate,
        // later design pass — bind the null (always-zero-confidence)
        // provider so Ratio Bot V2 recovery review works today, safely
        // falling back to CONTINUE_WAIT until a real implementation exists.
        $this->app->bind(AnchorCandidateProviderInterface::class, NullAnchorCandidateProvider::class);

        // Real transport for App\Domain\AuditResearch\ServerDiagnostics --
        // phpseclib/phpseclib installed successfully in this environment
        // (composer.lock), so the live (non-sandbox) SSH path is real, not a
        // stub. Sandbox-mode connections never reach this class at all (see
        // ServerDiagnosticsService::isSandbox()).
        $this->app->bind(SshExecutor::class, PhpseclibSshExecutor::class);

        // Prediction providers are deliberately exposed as one shadow-only
        // observatory. Nothing in this binding can influence order execution.
        $this->app->singleton(PredictionObservatory::class, function ($app) {
            return new PredictionObservatory([
                $app->make(LegacyAutoFinXPredictionProvider::class),
                $app->make(MarketStructurePredictionProvider::class),
                $app->make(ExternalMlPredictionProvider::class),
                $app->make(PineStrategyPredictionProvider::class),
            ]);
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
