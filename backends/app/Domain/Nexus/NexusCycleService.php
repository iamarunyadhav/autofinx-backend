<?php

namespace App\Domain\Nexus;

use App\Jobs\RunNexusCycleJob;
use App\Models\NexusEntry;
use App\Models\NexusRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

final class NexusCycleService
{
    public function __construct(
        private readonly NexusStore $store,
        private readonly NexusCapabilityRegistryService $capabilities,
        private readonly NexusObservationService $observer,
        private readonly NexusSourceService $sources,
        private readonly NexusDiscoveryService $discovery,
        private readonly NexusReasoningService $reasoning,
        private readonly NexusReportService $reports,
    ) {}

    public function dispatch(string $workspace, string $kind = 'cycle'): NexusRun
    {
        if (! config('nexus.enabled')) {
            throw new RuntimeException('Nexus is disabled.');
        }
        $connection = config('nexus.connection');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['redis', 'database'], true)) {
            throw new RuntimeException('Nexus requires a durable asynchronous queue.');
        }
        if ((int) config('queue.connections.'.$connection.'.retry_after') <= 110) {
            throw new RuntimeException('Nexus queue retry_after must exceed the 110-second worker timeout.');
        }
        $lock = Cache::lock('nexus:dispatch:'.$workspace, 10);

        return $lock->block(2, function () use ($workspace, $kind, $connection) {
            // Recover orphaned workers/queued records; never mark them as successful.
            NexusRun::where('workspace_id', $workspace)->whereIn('status', ['queued', 'running'])
                ->where('updated_at', '<', now()->subHour())->update(['status' => 'failed', 'error_code' => 'WORKER_OR_QUEUE_TIMEOUT', 'finished_at' => now()]);
            $slot = (string) intdiv(now()->timestamp, 900);
            $existing = NexusRun::where('workspace_id', $workspace)->where('slot', $slot)->first();
            if ($existing) {
                return $existing;
            }
            if (Queue::connection($connection)->size(config('nexus.queue')) >= config('nexus.max_pending')) {
                throw new RuntimeException('Nexus queue backlog limit reached.');
            }
            $run = NexusRun::create(['workspace_id' => $workspace, 'slot' => $slot, 'kind' => $kind, 'status' => 'queued']);
            try {
                RunNexusCycleJob::dispatch($run->id)->onConnection($connection)->onQueue(config('nexus.queue'));
            } catch (\Throwable $e) {
                $run->update(['status' => 'failed', 'error_code' => 'DISPATCH_FAILED', 'finished_at' => now()]);
                throw $e;
            }

            return $run;
        });
    }

    public function execute(NexusRun $run): array
    {
        $workspace = $run->workspace_id;
        $capabilities = $this->capabilities->snapshot();
        $this->store->put($workspace, 'capability', 'latest', 'Capability inventory', ['items' => $capabilities]);
        $observations = $this->observer->capture($workspace);
        $observations['capability_gaps'] = app(NexusCapabilityGapService::class)->detect($capabilities, $observations);
        $this->store->put($workspace, 'observation', $run->slot, 'System observation', $observations);
        $sourceStates = [];
        $reasoning = 'OBSERVE_ONLY';
        if ((int) config('nexus.autonomy') >= 1) {
            $sourceStates = $this->sources->collect($workspace);
            $this->discovery->baseline($workspace, $observations);
            $reasoning = $this->reasoning->run($workspace, $capabilities, $run->kind === 'weekly', [
                'gaps' => $observations['capability_gaps'],
                'outcomes' => array_slice($observations['quick_trade_outcomes'], 0, 10),
            ]);
        }
        $this->reports->publish($workspace);
        if (now()->isMonday() || $run->kind === 'weekly') {
            $this->reports->publish($workspace, true);
        }
        $this->reports->exportKnowledge($workspace);
        // Bounded cleanup; durable recommendations/reviews/cost ledgers are retained.
        $ids = NexusEntry::where('workspace_id', $workspace)->whereIn('kind', ['observation', 'daily-report', 'weekly-report'])
            ->where('created_at', '<', now()->subDays(config('nexus.retention_days')))->limit(500)->pluck('id');
        NexusEntry::whereIn('id', $ids)->delete();
        $oldRuns = NexusRun::where('workspace_id', $workspace)->whereNotIn('status', ['queued', 'running'])
            ->where('created_at', '<', now()->subDays(config('nexus.retention_days')))->limit(500)->pluck('id');
        NexusRun::whereIn('id', $oldRuns)->delete();
        NexusEntry::where('workspace_id', $workspace)->where('kind', 'experiment')->whereIn('status', ['QUEUED', 'RUNNING'])
            ->where('updated_at', '<', now()->subHour())->update(['status' => 'FAILED']);

        return ['sources' => $sourceStates, 'reasoning' => $reasoning, 'capabilities' => count($capabilities),
            'partial' => in_array('UNAVAILABLE', $sourceStates, true)];
    }
}
