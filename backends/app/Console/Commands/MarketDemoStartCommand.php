<?php

namespace App\Console\Commands;

use App\Support\MarketDemoGenerator;
use Illuminate\Console\Command;
use App\Support\Clock\Clock;

class MarketDemoStartCommand extends Command
{
    protected $signature = 'market:demo-start {--once} {--symbol=} {--interval=2}';
    protected $description = 'Generate continuous demo market ticks/candles for symbols';

    public function handle(MarketDemoGenerator $generator, Clock $clock): int
    {
        $symbolOption = $this->option('symbol');
        $symbols = $symbolOption
            ? explode(',', $symbolOption)
            : explode(',', env('DEMO_MARKET_SYMBOLS', 'BTCUSDT,ETHUSDT,AAPL'));

        $interval = (int) ($this->option('interval') ?? env('DEMO_MARKET_TICK_INTERVAL_SECONDS', 2));
        $once = $this->option('once');

        $loop = function () use ($generator, $clock, $symbols, $interval, $once) {
            do {
                $now = $clock->now();
                $count = $generator->generateBurst(array_map('trim', $symbols), $now);
                $this->info("{$now->toDateTimeString()} generated {$count} ticks.");
                if ($once) {
                    break;
                }
                sleep(max(1, $interval));
            } while (true);
        };

        $loop();
        return self::SUCCESS;
    }
}
