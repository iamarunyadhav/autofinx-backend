<?php

namespace App\Console\Commands;

use App\Domain\MarketContext\MarketContextIngestionService;
use Illuminate\Console\Command;

/** Fetches the fixed Market Context source registry. Does nothing while the module flag is off. */
class MarketContextCollectCommand extends Command
{
    protected $signature = 'market-context:collect
        {--source=* : Only these source ids}
        {--force : Ignore each source\'s refresh interval (the failure breaker still applies)}
        {--backfill-fng= : One-off Fear & Greed history load, N days (max from config)}';

    protected $description = 'Market Context: collect public news/metrics into mctx_* tables (shadow-only, flag-gated).';

    public function handle(MarketContextIngestionService $ingestion): int
    {
        if (! MarketContextIngestionService::enabled()) {
            $this->line('Market Context is disabled (AUTOFINX_MARKET_CONTEXT_ENABLED=false); nothing done.');

            return self::SUCCESS;
        }
        if ($this->option('backfill-fng') !== null) {
            $result = $ingestion->backfillFearGreed((int) $this->option('backfill-fng'));
            $this->line('fear_greed backfill: '.json_encode($result));

            return self::SUCCESS;
        }
        foreach ($ingestion->run((array) $this->option('source'), (bool) $this->option('force')) as $id => $summary) {
            $this->line(sprintf('%-24s %s', $id, json_encode($summary)));
        }

        return self::SUCCESS;
    }
}
