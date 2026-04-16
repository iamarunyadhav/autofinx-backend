<?php

namespace App\Jobs;

use App\Domain\Trading\ProviderToCashSweepService;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SweepProviderBalanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $orderId,
        public readonly ?string $traceId = null
    ) {
        $this->onQueue('reconcile');
    }

    public function handle(ProviderToCashSweepService $sweeps): void
    {
        $order = Order::find($this->orderId);
        if (! $order) {
            return;
        }

        if (strtolower((string) $order->side) !== 'sell') {
            return;
        }

        if (! in_array(strtoupper((string) $order->status), ['FILLED', 'PARTIAL'], true)) {
            return;
        }

        $sweeps->runForOrder($order, $this->traceId);
    }
}
