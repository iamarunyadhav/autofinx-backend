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
use App\Jobs\DetectStaleFeedsJob;
use App\Jobs\KeepAliveUserStreamsJob;
use App\Jobs\RunWithdrawalSchedulesJob;
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
        $cron = config('exchange.sync_cron', '*/10 * * * *');
        $schedule->command('connections:sync')
            ->cron($cron)
            ->onOneServer()
            ->runInBackground()
            ->before(function () { $this->markRunStart('connections:sync'); })
            ->after(function () { $this->markRunEnd('connections:sync'); })
            ->description('Sync exchange connections and refresh balances/positions');

        $schedule->command('strategy:heal')
            ->everyTenMinutes()
            ->onOneServer()
            ->runInBackground()
            ->before(function () { $this->markRunStart('strategy:heal'); })
            ->after(function () { $this->markRunEnd('strategy:heal'); })
            ->description('Heal strategies after maintenance and retry failed orders');

        /*
        Temporarily disabled to reduce unnecessary Binance REST pressure while only Future Trade and Fast2X are needed.
        $schedule->command('orders:poll-status')
            ->everyTwoMinutes()
            ->onOneServer()
            ->runInBackground()
            ->before(function () { $this->markRunStart('orders:poll-status'); })
            ->after(function () { $this->markRunEnd('orders:poll-status'); })
            ->description('Poll provider status for pending orders');
        */

        $schedule->command('market:ws-consume --once --store-ohlc')
            ->everySecond()
            ->withoutOverlapping()
            ->runInBackground()
            ->description('Refresh live price cache and rolling OHLC snapshots');

        $schedule->command('metals:poll-prices --once')
            ->everyFiveSeconds()
            ->withoutOverlapping()
            ->runInBackground()
            ->description('Poll metal broker rates and cache USD/LKR conversions');

        /*
        Temporarily disabled to reduce unnecessary Binance REST pressure while only Future Trade and Fast2X are needed.
        $schedule->command('bots:evaluate')
            ->everySecond()
            ->withoutOverlapping()
            ->runInBackground()
            ->description('Evaluate running bots and emit executable trade signals');

        $schedule->command('ratio-bots:evaluate')
            ->everySecond()
            ->withoutOverlapping()
            ->runInBackground()
            ->description('Evaluate stateful anchor-based ratio bots');
        */

        $schedule->command('future-trade:scan-all')
            ->everySecond()
            ->withoutOverlapping()
            ->runInBackground()
            ->before(function () { $this->markRunStart('future-trade:scan-all'); })
            ->after(function () { $this->markRunEnd('future-trade:scan-all'); })
            ->description('Scan all enabled FutureTrade bot workspaces');

        $schedule->command('future-trade:diagnostics --persist')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground()
            ->before(function () { $this->markRunStart('future-trade:diagnostics'); })
            ->after(function () { $this->markRunEnd('future-trade:diagnostics'); })
            ->description('Persist FutureTrade runtime diagnostics and blocker snapshots');

        /*
        Temporarily disabled to reduce unnecessary Binance REST pressure while only Future Trade and Fast2X are needed.
        $schedule->command('ratio-bots:reconcile')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground()
            ->description('Reconcile ratio bot orders and positions against exchange state');

        $schedule->job(new SyncOrderStatusJob(100))
            ->everyTenSeconds()
            ->withoutOverlapping()
            ->description('Reconcile order status/fills from exchange REST fallback');
        */

        $schedule->job(new KeepAliveUserStreamsJob())
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->description('Keep Binance user stream listen keys alive');

        $schedule->job(new DetectStaleFeedsJob((int) env('LIVE_TRADING_STALE_FEED_THRESHOLD_SEC', 60)))
            ->everyMinute()
            ->withoutOverlapping()
            ->description('Activate or clear stale feed breaker for live trading');

        $schedule->job(new RunWithdrawalSchedulesJob())
            ->everyMinute()
            ->withoutOverlapping()
            ->description('Run due automated withdrawal schedules');

        /*
        Temporarily disabled to reduce unnecessary Binance REST pressure while only Future Trade and Fast2X are needed.
        $schedule->job(new ExpireAiDecisionsJob())
            ->everyMinute()
            ->withoutOverlapping()
            ->description('Expire stale AI trade approvals');

        $schedule->job(new DispatchAiDecisionCycleJob())
            ->everyMinute()
            ->withoutOverlapping()
            ->description('Dispatch scheduled AI decision cycles for enabled spot workspaces');

        $schedule->job(new RunExitMonitoringJob())
            ->everyTenSeconds()
            ->withoutOverlapping()
            ->description('Evaluate managed live spot exits and dispatch protective exits safely');
        */

        $schedule->command('ops:readiness-check')
            ->everyMinute()
            ->onOneServer()
            ->withoutOverlapping()
            ->description('Persist readiness snapshots and sync readiness incidents');

        $schedule->command('ops:maturity-cycle')
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping()
            ->description('Capture operational trend snapshots, drift detections, and maintenance confidence');
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

    private function markRunStart(string $command): void
    {
        \App\Models\SchedulerRun::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'command' => $command,
            'started_at' => now(),
            'status' => 'running',
        ]);
    }

    private function markRunEnd(string $command): void
    {
        $run = \App\Models\SchedulerRun::where('command', $command)->orderByDesc('started_at')->first();
        if (! $run) return;
        $run->finished_at = now();
        $run->duration_ms = $run->started_at ? $run->started_at->diffInMilliseconds($run->finished_at) : null;
        $run->status = 'completed';
        $run->save();
    }
}
