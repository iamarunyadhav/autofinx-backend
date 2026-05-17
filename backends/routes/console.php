<?php

use App\Jobs\DetectStaleFeedsJob;
use App\Jobs\KeepAliveUserStreamsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\SchedulerRun;
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

Schedule::command('market:ws-consume --once --store-ohlc')
    ->everySecond()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('market:ws-consume --once --market-type=futures --store-ohlc --ohlc-intervals=1m,5m,15m,1h,4h')
    ->everySecond()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('metals:poll-prices --once')
    ->everyFiveSeconds()
    ->withoutOverlapping()
    ->runInBackground()
    ->before(fn () => $markRunStart('metals:poll-prices'))
    ->after(fn () => $markRunEnd('metals:poll-prices'));

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

Schedule::job(new KeepAliveUserStreamsJob())
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->before(fn () => $markRunStart('KeepAliveUserStreamsJob'))
    ->after(fn () => $markRunEnd('KeepAliveUserStreamsJob'));

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
Schedule::command('future-trade:tick --duration=55 --sleep-ms=1000')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer()
    ->runInBackground()
    ->before(fn () => $markRunStart('future-trade:tick'))
    ->after(fn () => $markRunEnd('future-trade:tick'));

Schedule::command('future-trade:diagnostics --persist')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->before(fn () => $markRunStart('future-trade:diagnostics'))
    ->after(fn () => $markRunEnd('future-trade:diagnostics'));

Schedule::command('future-trade:audit-missed-signals --limit=100')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->before(fn () => $markRunStart('future-trade:audit-missed-signals'))
    ->after(fn () => $markRunEnd('future-trade:audit-missed-signals'));

Schedule::command('future-trade:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->before(fn () => $markRunStart('future-trade:reconcile'))
    ->after(fn () => $markRunEnd('future-trade:reconcile'));

// Bounded futures user-stream consumer — runs in 55s slices and re-spawns each
// minute. withoutOverlapping/onOneServer guarantee at most one consumer per
// cluster; the command itself takes a secondary cache lock so a misconfigured
// crontab cannot spawn two consumers on the same workspace.
Schedule::command('future-trade:user-stream --duration=55')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer()
    ->runInBackground()
    ->before(fn () => $markRunStart('future-trade:user-stream'))
    ->after(fn () => $markRunEnd('future-trade:user-stream'));
