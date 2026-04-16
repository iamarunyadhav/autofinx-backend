<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Domain\Trading\LiveExecutionEventService;

class ReconcileOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('ops');
    }

    public function handle(LiveExecutionEventService $events): void
    {
        $stale = Order::whereIn('status', [Order::STATUS_PENDING_PROVIDER, Order::STATUS_UNCERTAIN, Order::STATUS_NEW, Order::STATUS_PARTIAL])
            ->where('created_at', '<', now()->subHour())
            ->get();

        foreach ($stale as $order) {
            $order->status = Order::STATUS_UNCERTAIN;
            $order->uncertain_at = $order->uncertain_at ?: now();
            $order->reconciliation_state = Order::RECONCILIATION_UNCERTAIN;
            $order->meta = array_merge((array) ($order->meta ?? []), [
                'stale_pending_detected_at' => now()->toIso8601String(),
            ]);
            $order->save();

            $events->recordAnomaly(
                $order,
                'stale_pending_order',
                'Live order remained unresolved beyond the reconcile safety window.',
                [
                    'status' => $order->status,
                    'age_minutes' => $order->created_at?->diffInMinutes(now()),
                ],
                'warning',
                'stale_reconcile'
            );
        }
    }
}
