<?php

use App\Jobs\DetectStaleFeedsJob;
use App\Jobs\KeepAliveUserStreamsJob;
use App\Models\SchedulerRun;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

$markRunStart = function (string $command): void {
    SchedulerRun::create([
        'id' => (string) Str::uuid(),
        'command' => $command,
        'started_at' => now(),
        'status' => 'running',
    ]);
};

$markRunEnd = function (string $command): void {
    $run = SchedulerRun::where('command', $command)->orderByDesc('started_at')->first();
    if (! $run) {
        return;
    }

    $run->finished_at = now();
    $run->duration_ms = $run->started_at
        ? $run->started_at->diffInMilliseconds($run->finished_at)
        : null;
    $run->status = 'completed';
    $run->save();
};

$sharedHostingMode = (bool) config('future_trade.shared_hosting_mode', true);
$schedulerEnabled = (bool) config('autofinx.scheduler.enabled', true);
$supervisorRuntime = (bool) config('autofinx.runtime.supervisor', false);
$allowMarketConsumers = (bool) config('autofinx.scheduler.market_consumers', false);
$allowBinanceRest = (bool) config('autofinx.scheduler.binance_rest', false);
$allowMetals = (bool) config('autofinx.scheduler.metals', false);
$allowRuntimeAutoRepair = ! $supervisorRuntime && (bool) config('autofinx.scheduler.runtime_auto_repair', true);
$scheduleFutureTradeRuntime = (bool) config('future_trade.schedule.runtime_commands', config('future_trade.schedule_runtime_commands', false));
$scheduleFutureTradeDiagnostics = (bool) config('future_trade.schedule.diagnostics', false);
$scheduleFutureTradeAuditMissedSignals = (bool) config('future_trade.schedule.audit_missed_signals', false);
$scheduleFutureTradeReconcile = (bool) config('future_trade.schedule.reconcile', false);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('connections:sync')
    ->cron(config('exchange.sync_cron', '*/10 * * * *'))
    ->onOneServer()
    ->runInBackground()
    ->before(fn () => $markRunStart('connections:sync'))
    ->after(fn () => $markRunEnd('connections:sync'));

Schedule::command('strategy:heal')
    ->everyTenMinutes()
    ->onOneServer()
    ->runInBackground()
    ->before(fn () => $markRunStart('strategy:heal'))
    ->after(fn () => $markRunEnd('strategy:heal'));

/*
Temporarily disabled to reduce unnecessary Binance REST pressure while only Future Trade and Fast2X are needed.
Schedule::command('orders:poll-status')
    ->everyTwoMinutes()
    ->onOneServer()
    ->runInBackground();
*/

if ($schedulerEnabled && ! $supervisorRuntime && ! $sharedHostingMode && $allowMarketConsumers) {
    Schedule::command('market:ws-consume --once --store-ohlc')
        ->everyTenSeconds()
        ->withoutOverlapping()
        ->runInBackground();

    Schedule::command('market:ws-consume --once --market-type=futures --store-ohlc --ohlc-intervals=1m,5m,15m,1h,4h')
        ->everyTenSeconds()
        ->withoutOverlapping()
        ->runInBackground();
}

if ($schedulerEnabled && $allowMetals) {
    Schedule::command('metals:poll-prices --once')
        ->everyFiveSeconds()
        ->withoutOverlapping()
        ->runInBackground()
        ->before(fn () => $markRunStart('metals:poll-prices'))
        ->after(fn () => $markRunEnd('metals:poll-prices'));
}

// Candle Direction / Robot Trade autonomous evaluation. Runs every 10 seconds so
// RUNNING robot sessions open/manage/close positions without manual "Run cycle".
// Disable with config autofinx.scheduler.robot_sessions=false if REST pressure
// becomes a concern.
if ($schedulerEnabled && (bool) config('autofinx.scheduler.robot_sessions', true)) {
    Schedule::command('robot-sessions:evaluate')
        ->everyTenSeconds()
        ->withoutOverlapping()
        ->runInBackground()
        ->before(fn () => $markRunStart('robot-sessions:evaluate'))
        ->after(fn () => $markRunEnd('robot-sessions:evaluate'));
}

if ($schedulerEnabled && (bool) config('autofinx.scheduler.quick_settler', true)) {
    Schedule::command('quick-trades:settle')
        ->everyTenSeconds()
        ->withoutOverlapping()
        ->runInBackground()
        ->before(fn () => $markRunStart('quick-trades:settle'))
        ->after(fn () => $markRunEnd('quick-trades:settle'));
}

if ($schedulerEnabled && $allowRuntimeAutoRepair) {
    Schedule::command('runtime:repair --attempts=1 --sleep=2')
        ->everyMinute()
        ->withoutOverlapping()
        ->runInBackground()
        ->before(fn () => $markRunStart('runtime:repair'))
        ->after(fn () => $markRunEnd('runtime:repair'));
}

/*
Temporarily disabled to reduce unnecessary Binance REST pressure while only Future Trade and Fast2X are needed.
Schedule::command('bots:evaluate')
    ->everySecond()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('ratio-bots:evaluate')
    ->everySecond()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('robot-sessions:evaluate')
    ->everyFiveSeconds()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('quick-trades:settle')
    ->everySecond()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::job(new SyncOrderStatusJob(100))
    ->everyTenSeconds()
    ->withoutOverlapping();
*/

if ($schedulerEnabled && ! $supervisorRuntime && $allowBinanceRest) {
    Schedule::job(new KeepAliveUserStreamsJob)
        ->everyThirtyMinutes()
        ->withoutOverlapping()
        ->before(fn () => $markRunStart('KeepAliveUserStreamsJob'))
        ->after(fn () => $markRunEnd('KeepAliveUserStreamsJob'));
}

Schedule::job(new DetectStaleFeedsJob((int) env('LIVE_TRADING_STALE_FEED_THRESHOLD_SEC', 60)))
    ->everyMinute()
    ->withoutOverlapping()
    ->before(fn () => $markRunStart('DetectStaleFeedsJob'))
    ->after(fn () => $markRunEnd('DetectStaleFeedsJob'));

Schedule::command('jobs:maintain-backlog')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->before(fn () => $markRunStart('jobs:maintain-backlog'))
    ->after(fn () => $markRunEnd('jobs:maintain-backlog'));

// Future-trade tick: withoutOverlapping uses a cache lock under the hood, but
// we also (a) bound the lock to 60s so a crashed run cannot wedge forever, and
// (b) onOneServer to prevent duplicate execution when scheduling on multiple
// workers. The command itself takes a secondary cache lock keyed on the
// workspace, so even a misconfigured cron cannot spawn two scanner loops on
// the same workspace.
if ($schedulerEnabled && ! $supervisorRuntime && $scheduleFutureTradeRuntime) {
    Schedule::command('future-trade:tick --duration=55 --sleep-ms=1000')
        ->everyMinute()
        ->withoutOverlapping(2)
        ->onOneServer()
        ->runInBackground()
        ->before(fn () => $markRunStart('future-trade:tick'))
        ->after(fn () => $markRunEnd('future-trade:tick'));
}

if ($schedulerEnabled && $scheduleFutureTradeDiagnostics) {
    Schedule::command('future-trade:diagnostics --persist')
        ->everyFiveMinutes()
        ->withoutOverlapping(5)
        ->onOneServer()
        ->before(fn () => $markRunStart('future-trade:diagnostics'))
        ->after(fn () => $markRunEnd('future-trade:diagnostics'));
}

if ($schedulerEnabled && $scheduleFutureTradeAuditMissedSignals) {
    Schedule::command('future-trade:audit-missed-signals --limit=100')
        ->everyFiveMinutes()
        ->withoutOverlapping(5)
        ->onOneServer()
        ->before(fn () => $markRunStart('future-trade:audit-missed-signals'))
        ->after(fn () => $markRunEnd('future-trade:audit-missed-signals'));
}

if ($schedulerEnabled && $scheduleFutureTradeReconcile) {
    Schedule::command('future-trade:reconcile')
        ->everyTenMinutes()
        ->withoutOverlapping(10)
        ->onOneServer()
        ->before(fn () => $markRunStart('future-trade:reconcile'))
        ->after(fn () => $markRunEnd('future-trade:reconcile'));
}

// Bounded futures user-stream consumer — runs in 55s slices and re-spawns each
// minute. withoutOverlapping/onOneServer guarantee at most one consumer per
// cluster; the command itself takes a secondary cache lock so a misconfigured
// crontab cannot spawn two consumers on the same workspace.
if ($schedulerEnabled && ! $supervisorRuntime && $scheduleFutureTradeRuntime) {
    Schedule::command('future-trade:user-stream --duration=55')
        ->everyMinute()
        ->withoutOverlapping(2)
        ->onOneServer()
        ->runInBackground()
        ->before(fn () => $markRunStart('future-trade:user-stream'))
        ->after(fn () => $markRunEnd('future-trade:user-stream'));
}
