<?php

namespace App\Jobs;

use App\Domain\Intelligence\AiDecisionCycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RunAiDecisionCycleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $symbol,
        public readonly ?string $integrationId = null,
    ) {
        // 'intelligence' queue is dedicated to AI decision cycles.
        // Separate from 'execution', 'reconciliation', and 'notifications' queues
        // so a slow AI provider cannot block order execution or reconciliation work.
        $this->onQueue('intelligence');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('ai-cycle:' . $this->workspaceId . ':' . strtoupper($this->symbol)))->expireAfter(55),
        ];
    }

    public function handle(AiDecisionCycleService $service): void
    {
        $service->run($this->workspaceId, $this->symbol, [
            'integration_id' => $this->integrationId,
            'manual_trigger' => false,
        ]);
    }
}
