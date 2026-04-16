<?php

namespace Services\RiskService\Jobs;

use App\Models\StrategyInstance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Services\ExecutionService\Jobs\ExecuteOrder;

class EvaluateRisk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $queue = 'risk';

    public function __construct(
        public string $instanceId,
        public object $signal,
        public array $context = []
    ) {
    }

    public function handle(): void
    {
        $instance = StrategyInstance::find($this->instanceId);
        if (! $instance) {
            return;
        }

        // Simplified risk check: approve everything in demo mode
        ExecuteOrder::dispatch($instance->workspace_id, $instance->id, $this->signal, array_merge([
            'mode' => $instance->mode,
            'connection_id' => $instance->mode === 'live' ? $instance->connection_id : null,
        ], $this->context));
    }
}
