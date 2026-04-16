<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ComputeAnalyticsJob;

class AnalyticsRecomputeCommand extends Command
{
    protected $signature = 'analytics:recompute {workspace}';
    protected $description = 'Recompute analytics snapshot for a workspace';

    public function handle(): int
    {
        $workspace = $this->argument('workspace');
        ComputeAnalyticsJob::dispatchSync($workspace);
        $this->info('Analytics recomputed for workspace ' . $workspace);
        return self::SUCCESS;
    }
}
