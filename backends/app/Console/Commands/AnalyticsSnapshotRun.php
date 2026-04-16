<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\Analytics\AnalyticsService;

class AnalyticsSnapshotRun extends Command
{
    protected $signature = 'analytics:snapshot-run {--once} {--interval=300} {--workspace=}';
    protected $description = 'Compute analytics snapshots';

    public function handle(AnalyticsService $service): int
    {
        $workspace = $this->option('workspace');
        $interval = (int) $this->option('interval');
        $once = $this->option('once');

        do {
            if ($workspace) {
                $service->snapshot($workspace);
                $this->info('Snapshot computed');
            }
            if ($once) break;
            sleep($interval);
        } while (true);

        return Command::SUCCESS;
    }
}
