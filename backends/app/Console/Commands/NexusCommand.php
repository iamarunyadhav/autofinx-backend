<?php

namespace App\Console\Commands;

use App\Domain\Nexus\NexusCycleService;
use App\Domain\Nexus\NexusReportService;
use App\Domain\Nexus\NexusStore;
use App\Models\NexusRun;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class NexusCommand extends Command
{
    protected $signature = 'nexus:run-cycle {workspace? : Workspace UUID; omit to dispatch all workspaces}';

    protected $description = 'Dispatch bounded Nexus research cycles; never invokes trading or deployment.';

    protected $aliases = ['nexus:status', 'nexus:doctor', 'nexus:observe', 'nexus:discover', 'nexus:research',
        'nexus:capability-scan', 'nexus:daily-report', 'nexus:weekly-innovation-report', 'nexus:export-knowledge', 'nexus:cost-status'];

    public function handle(NexusCycleService $cycles, NexusReportService $reports, NexusStore $store): int
    {
        $command = $this->input->getFirstArgument();
        $ready = Schema::hasTable('nexus_runs');
        if (in_array($command, ['nexus:status', 'nexus:doctor'], true)) {
            $retry = (int) config('queue.connections.'.config('nexus.connection').'.retry_after');
            $this->line(json_encode(['enabled' => config('nexus.enabled'), 'database_ready' => $ready,
                'connection' => config('nexus.connection'), 'queue' => config('nexus.queue'), 'cadence_minutes' => 15,
                'retry_after_safe' => $retry > 110, 'safety' => $store->safety(),
                'provider_configured' => (bool) config('nexus.llm.url') && (bool) config('nexus.llm.key'),
                'pending_runs' => $ready ? NexusRun::whereIn('status', ['queued', 'running'])->count() : null,
                'production_workers_verified' => false], JSON_PRETTY_PRINT));

            return $ready && $retry > 110 ? self::SUCCESS : self::FAILURE;
        }
        if (! $ready) {
            $this->error('Nexus migration is pending.');

            return self::FAILURE;
        }
        $workspace = $this->argument('workspace');
        if ($workspace && ! Workspace::whereKey($workspace)->exists()) {
            $this->error('Unknown workspace.');

            return self::FAILURE;
        }
        if (in_array($command, ['nexus:export-knowledge', 'nexus:cost-status'], true)) {
            if (! $workspace) {
                $this->error('A workspace UUID is required for exports and cost details.');

                return self::FAILURE;
            }
            $this->line(json_encode($command === 'nexus:export-knowledge' ? $reports->exportKnowledge($workspace) : $reports->cost($workspace), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        if (! config('nexus.enabled')) {
            $this->info('Nexus disabled; no work dispatched.');

            return self::SUCCESS;
        }
        $count = 0;
        foreach (Workspace::query()->when($workspace, fn ($q) => $q->whereKey($workspace))->lazyById(50) as $w) {
            try {
                $cycles->dispatch($w->id, $command === 'nexus:weekly-innovation-report' ? 'weekly' : 'cycle');
                $count++;
            } catch (Throwable $e) {
                $this->error('Nexus dispatch could not complete: '.class_basename($e));

                return self::FAILURE;
            }
        }
        $this->info("Nexus cycles requested for {$count} workspace(s). Same 15-minute slots are deduplicated.");

        return self::SUCCESS;
    }
}
