<?php

namespace App\Jobs;

use App\Domain\Trading\ExitMonitoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RunExitMonitoringJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $workspaceId = null,
    ) {
        $this->onQueue('monitoring');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('exit-monitor:' . ($this->workspaceId ?: 'all')))->expireAfter(25),
        ];
    }

    public function handle(ExitMonitoringService $service): void
    {
        if ($this->workspaceId) {
            $service->runWorkspace($this->workspaceId);

            return;
        }

        $service->runAll();
    }
}
