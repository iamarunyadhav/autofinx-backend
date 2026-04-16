<?php

namespace Services\MarketService\Console\Commands;

use Illuminate\Console\Command;
use Services\MarketService\Jobs\GenerateDemoTick;

class StartDemoMarket extends Command
{
    protected $signature = 'market:demo-start {--symbol=BTCUSDT} {--interval=5}';

    protected $description = 'Start synthetic market feed for demo mode';

    public function handle(): int
    {
        $symbol = $this->option('symbol');
        $interval = (int) $this->option('interval');

        $this->info("Starting demo market for {$symbol} every {$interval}s. Press Ctrl+C to stop.");

        while (true) {
            GenerateDemoTick::dispatch($symbol);
            sleep($interval);
        }
    }
}
