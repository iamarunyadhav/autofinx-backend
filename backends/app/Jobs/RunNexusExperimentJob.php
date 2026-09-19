<?php

namespace App\Jobs;

use App\Domain\Nexus\NexusExperimentService;
use App\Models\NexusEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RunNexusExperimentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 110;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $entryId) {}

    public function handle(NexusExperimentService $service): void
    {
        if (! config('nexus.enabled') || config('nexus.autonomy') < 2) {
            NexusEntry::whereKey($this->entryId)->where('status', 'QUEUED')->update(['status' => 'CANCELLED']);

            return;
        }
        if (! NexusEntry::whereKey($this->entryId)->where('kind', 'experiment')->where('status', 'QUEUED')->update(['status' => 'RUNNING'])) {
            return;
        }
        $entry = NexusEntry::findOrFail($this->entryId);
        $result = $service->evaluate($entry);
        $entry->update(['status' => $result['status'], 'payload' => $entry->payload + ['result' => $result], 'evidence_level' => $result['evidence_level'] ?? 'C']);
    }

    public function failed(?Throwable $e): void
    {
        NexusEntry::whereKey($this->entryId)->whereIn('status', ['RUNNING', 'QUEUED'])->update(['status' => 'FAILED']);
    }
}
