<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\Market\MarketBackfillService;

class MarketBackfillCommand extends Command
{
    protected $signature = 'market:backfill {symbol} {--from=} {--to=}';
    protected $description = 'Backfill synthetic candles for a symbol (demo-safe)';

    public function handle(MarketBackfillService $service): int
    {
        $symbol = $this->argument('symbol');
        $from = $this->option('from');
        $to = $this->option('to');
        $count = $service->backfill($symbol, $from, $to);
        $this->info("Backfilled {$count} candles for {$symbol}");
        return self::SUCCESS;
    }
}
