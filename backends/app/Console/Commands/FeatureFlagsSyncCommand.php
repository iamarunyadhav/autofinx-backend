<?php

namespace App\Console\Commands;

use App\Domain\Ops\FeatureFlagService;
use Illuminate\Console\Command;

/**
 * Ensures every flag key in FeatureFlagService::REGISTRY has a row in
 * `feature_flags`, seeded with the current real config default so
 * introducing the table never changes live behavior on its own. Safe to
 * run repeatedly (idempotent) -- e.g. after adding a new registry entry.
 */
class FeatureFlagsSyncCommand extends Command
{
    protected $signature = 'feature-flags:sync';

    protected $description = 'Seed/refresh feature_flags rows from the code-owned FeatureFlagService registry';

    public function handle(FeatureFlagService $service): int
    {
        $created = $service->sync();

        $this->info("Feature flags synced. {$created} new row(s) created; existing rows had metadata refreshed.");

        return self::SUCCESS;
    }
}
