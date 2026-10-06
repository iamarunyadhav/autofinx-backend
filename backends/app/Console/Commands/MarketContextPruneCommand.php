<?php

namespace App\Console\Commands;

use App\Domain\MarketContext\MarketContextIngestionService;
use App\Domain\MarketContext\MarketContextRetention;
use Illuminate\Console\Command;

class MarketContextPruneCommand extends Command
{
    protected $signature = 'market-context:prune';

    protected $description = 'Market Context: apply retention (bounded chunked deletes; flag-gated).';

    public function handle(MarketContextRetention $retention): int
    {
        if (! MarketContextIngestionService::enabled()) {
            $this->line('Market Context is disabled (AUTOFINX_MARKET_CONTEXT_ENABLED=false); nothing done.');

            return self::SUCCESS;
        }
        $this->line(json_encode($retention->prune()));

        return self::SUCCESS;
    }
}
