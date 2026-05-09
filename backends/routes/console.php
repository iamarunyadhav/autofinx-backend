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
    ->runInBackground()
    ->before(fn () => $markRunStart('market:ws-consume'))
    ->after(fn () => $markRunEnd('market:ws-consume'));

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

Schedule::command('future-trade:scan-all')
    ->everySecond()
    ->onOneServer()
    ->withoutOverlapping()
    ->runInBackground()
    ->before(fn () => $markRunStart('future-trade:scan-all'))
    ->after(fn () => $markRunEnd('future-trade:scan-all'));

Schedule::command('future-trade:diagnostics --persist')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->before(fn () => $markRunStart('future-trade:diagnostics'))
    ->after(fn () => $markRunEnd('future-trade:diagnostics'));
