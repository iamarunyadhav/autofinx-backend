<?php

namespace App\Jobs;

use App\Domain\Trading\LiveSignalExecutionService;
use App\Models\TradeSignal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteSignalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $signalId)
    {
        $this->onQueue('execution');
    }

    public function handle(LiveSignalExecutionService $execution): void
    {
        $signal = TradeSignal::find($this->signalId);
        if (! $signal) {
            return;
        }

        $execution->executeSignal($signal);
    }
}
