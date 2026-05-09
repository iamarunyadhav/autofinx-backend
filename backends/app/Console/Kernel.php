<?php

namespace App\Console;

use App\Console\Commands\ScheduleSimulateCommand;
use App\Console\Commands\BotsEvaluateCommand;
use App\Console\Commands\MarketWsConsumerCommand;
use App\Console\Commands\MarketDemoStartCommand;
use App\Console\Commands\ConnectionsSyncCommand;
use App\Console\Commands\StrategyHealCommand;
use App\Console\Commands\TradingLoopStartCommand;
use App\Console\Commands\BillingReconcileCommand;
use App\Console\Commands\VenuesSeedDefaultsCommand;
use App\Console\Commands\OpsStaleFeedCheckCommand;
use App\Console\Commands\OperationalReadinessCheckCommand;
use App\Console\Commands\OpsReconcileOrdersCommand;
use App\Console\Commands\AnalyticsRecomputeCommand;
use App\Console\Commands\MarketBackfillCommand;
use App\Console\Commands\ArbitrageLoopStart;
use App\Console\Commands\YieldSimulateStart;
use App\Console\Commands\DefiYieldSimulateStart;
use App\Console\Commands\AnalyticsSnapshotRun;
use App\Console\Commands\OperationalMaturityCycleCommand;
use App\Console\Commands\ReconcileOrdersCommand;
use App\Console\Commands\ReconcileTradesCommand;
use App\Console\Commands\ReconcileWalletCommand;
use App\Console\Commands\MetalsPricePollerCommand;
use App\Console\Commands\BinanceUserStreamCommand;
use App\Console\Commands\TradingHealthCheckCommand;
use App\Console\Commands\RatioBotsEvaluateCommand;
use App\Console\Commands\Fast2XRunCommand;
use App\Console\Commands\FutureTradeBotRunCommand;
use App\Console\Commands\FutureTradeDiagnosticsCommand;
use App\Console\Commands\FutureTradeScanAllCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
    }

    protected function schedule(Schedule $schedule): void
    {
        // All production schedule definitions live in routes/console.php.
        // Keep Kernel available for command registration only.
    }

    protected $commands = [
        ScheduleSimulateCommand::class,
        BotsEvaluateCommand::class,
        MarketWsConsumerCommand::class,
        MarketDemoStartCommand::class,
        ConnectionsSyncCommand::class,
        StrategyHealCommand::class,
        TradingLoopStartCommand::class,
        BillingReconcileCommand::class,
        VenuesSeedDefaultsCommand::class,
        OpsStaleFeedCheckCommand::class,
        OperationalReadinessCheckCommand::class,
        OpsReconcileOrdersCommand::class,
        AnalyticsRecomputeCommand::class,
        MarketBackfillCommand::class,
        ArbitrageLoopStart::class,
        YieldSimulateStart::class,
        DefiYieldSimulateStart::class,
        AnalyticsSnapshotRun::class,
        OperationalMaturityCycleCommand::class,
        ReconcileOrdersCommand::class,
        ReconcileTradesCommand::class,
        ReconcileWalletCommand::class,
        MetalsPricePollerCommand::class,
        BinanceUserStreamCommand::class,
        TradingHealthCheckCommand::class,
        RatioBotsEvaluateCommand::class,
        Fast2XRunCommand::class,
        FutureTradeBotRunCommand::class,
        FutureTradeDiagnosticsCommand::class,
        FutureTradeScanAllCommand::class,
    ];

}
