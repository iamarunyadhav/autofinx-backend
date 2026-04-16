<?php

use App\Jobs\DetectStaleFeedsJob;
use App\Jobs\KeepAliveUserStreamsJob;
use App\Jobs\SyncOrderStatusJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('connections:sync')
    ->cron(config('exchange.sync_cron', '*/10 * * * *'))
    ->onOneServer()
    ->runInBackground();

Schedule::command('strategy:heal')
    ->everyTenMinutes()
    ->onOneServer()
    ->runInBackground();

Schedule::command('orders:poll-status')
    ->everyTwoMinutes()
    ->onOneServer()
    ->runInBackground();

Schedule::command('market:ws-consume --once --store-ohlc')
    ->everySecond()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('metals:poll-prices --once')
    ->everyFiveSeconds()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('bots:evaluate')
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

Schedule::job(new KeepAliveUserStreamsJob())
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::job(new DetectStaleFeedsJob((int) env('LIVE_TRADING_STALE_FEED_THRESHOLD_SEC', 60)))
    ->everyMinute()
    ->withoutOverlapping();
