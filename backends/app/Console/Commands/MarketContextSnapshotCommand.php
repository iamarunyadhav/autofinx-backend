<?php

namespace App\Console\Commands;

use App\Domain\MarketContext\Data\ContextQuery;
use App\Domain\MarketContext\MarketContextIngestionService;
use App\Domain\MarketContext\SnapshotBuilder;
use App\Domain\MarketContext\SnapshotStore;
use App\Domain\MarketContext\Support\ProfileRegistry;
use Illuminate\Console\Command;

/** Computes and stores the bucketed rules snapshot for each enabled instrument (or one). */
class MarketContextSnapshotCommand extends Command
{
    protected $signature = 'market-context:snapshot {instrument? : e.g. BTC (default: every enabled profile)} {--dry : Print only, do not store}';

    protected $description = 'Market Context: build and store point-in-time snapshots (shadow-only, flag-gated).';

    public function handle(SnapshotBuilder $builder, SnapshotStore $store, ProfileRegistry $profiles): int
    {
        if (! MarketContextIngestionService::enabled()) {
            $this->line('Market Context is disabled (AUTOFINX_MARKET_CONTEXT_ENABLED=false); nothing done.');

            return self::SUCCESS;
        }
        $asOf = $store->bucket(now());
        $targets = $profiles->enabled();
        if ($this->argument('instrument')) {
            $key = strtoupper((string) $this->argument('instrument'));
            if (! isset($targets[$key])) {
                $this->error("No enabled profile for {$key}.");

                return self::FAILURE;
            }
            $targets = [$key => $targets[$key]];
        }
        foreach ($targets as $instrument => $profile) {
            $snapshot = $builder->build(new ContextQuery($profile['asset_class'], $instrument, $asOf,
                (int) config('market_context.snapshot.lookback_hours', 24) * 3600), now());
            if (! $this->option('dry')) {
                $store->store($snapshot);
            }
            $this->line(sprintf('%-6s as_of=%s freshness=%s risk=%s', $instrument, $asOf->toIso8601String(),
                $snapshot->freshness['state'], $snapshot->risk['level']));
        }

        return self::SUCCESS;
    }
}
