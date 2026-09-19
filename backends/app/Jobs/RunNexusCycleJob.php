<?php

namespace App\Jobs;

use App\Domain\Nexus\NexusCycleService;
use App\Models\NexusRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunNexusCycleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 110;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $runId) {}

    public function handle(NexusCycleService $service): void
    {
        $run = NexusRun::find($this->runId);
        if (! $run || $run->status !== 'queued') {
            return;
        }
        if (! config('nexus.enabled')) {
            $run->update(['status' => 'cancelled', 'finished_at' => now()]);

            return;
        }
        $lock = Cache::lock('nexus:cycle:'.$run->workspace_id, 150);
        if (! $lock->get()) {
            $run->update(['status' => 'skipped', 'error_code' => 'CYCLE_ALREADY_RUNNING', 'finished_at' => now()]);

            return;
        }
        try {
            if (! NexusRun::whereKey($run->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now()])) {
                return;
            }
            $summary = $service->execute($run);
            $run->update(['status' => $summary['partial'] ? 'partial' : 'completed', 'summary' => $summary, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $this->failed($e);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        NexusRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'error_code' => 'CYCLE_FAILED', 'finished_at' => now(),
        ]);
    }
}
