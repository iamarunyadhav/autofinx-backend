<?php

namespace App\Jobs;

use App\Domain\Execution\ExecutionEngine;
use App\Models\StrategyInstance;
use App\Domain\CopyTrading\CopyTradingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ExecutePaperOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $strategyInstanceId,
        public string $action,
        public float $price,
        public array $meta = []
    ) {
        $this->onQueue('execution');
    }

    public function handle(ExecutionEngine $engine, CopyTradingService $copyService): void
    {
        $instance = StrategyInstance::find($this->strategyInstanceId);
        if (! $instance) {
            return;
        }

        $side = strtolower($this->action) === 'sell' ? 'sell' : 'buy';
        $idempotencyKey = $this->meta['idempotency_key'] ?? (string) Str::uuid();
        $symbol = $this->meta['symbol'] ?? 'BTCUSDT';
        $qty = $this->meta['qty'] ?? 0.01;

        $engine->executePaper(
            $instance->workspace_id,
            $instance->id,
            $symbol,
            $side,
            $qty,
            $this->price,
            $idempotencyKey
        );

        // Log copy trading replication intent (followers will be picked up separately)
        $copyService->replicate(
            $idempotencyKey,
            $instance->id,
            $symbol,
            $this->price,
            $qty,
            $side
        );
    }
}
