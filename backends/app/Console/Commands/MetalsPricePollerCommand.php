<?php

namespace App\Console\Commands;

use App\Domain\Market\MetalsMarketDataService;
use Illuminate\Console\Command;

class MetalsPricePollerCommand extends Command
{
    protected $signature = 'metals:poll-prices {--provider=metal_broker} {--symbols=XAUUSD,XAGUSD,XCUUSD} {--once} {--sleep=5}';

    protected $description = 'Poll metals prices and cache USD/LKR rates for Gold/Silver/Bronze flows.';

    public function handle(MetalsMarketDataService $metals): int
    {
        $provider = (string) $this->option('provider');
        $symbols = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('symbols')))));
        $once = (bool) $this->option('once');
        $sleep = max(1, (int) $this->option('sleep'));

        do {
            foreach ($symbols as $symbol) {
                try {
                    $metals->getPrice(strtoupper($symbol), 'LKR', $provider);
                } catch (\Throwable $e) {
                    $this->warn("Metals poll failed for {$symbol}: {$e->getMessage()}");
                }
            }

            if ($once) {
                break;
            }

            sleep($sleep);
        } while (true);

        return self::SUCCESS;
    }
}

