<?php

namespace App\Jobs;

use App\Domain\Trading\BinanceOrderSyncService;
use App\Models\Integration;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OrdersReconcileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?string $integrationId = null)
    {
        $this->onQueue('reconcile');
    }

    public function handle(BinanceOrderSyncService $sync): void
    {
        $orders = Order::with('symbol')
            ->whereIn('status', [
                'submitted',
                'ack',
                'partial',
                Order::STATUS_PENDING_PROVIDER,
                Order::STATUS_NEW,
                Order::STATUS_PARTIAL,
                Order::STATUS_UNCERTAIN,
            ])
            ->when($this->integrationId, fn ($query) => $query->where('integration_id', $this->integrationId))
            ->limit(50)
            ->get();

        foreach ($orders as $order) {
            $integration = $order->integration_id ? Integration::find($order->integration_id) : null;
            if ($integration && strtolower((string) $integration->provider_key) !== 'binance') {
                continue;
            }

            try {
                $sync->sync($order, $order->trace_id ?: null, 'scheduled_reconcile');
            } catch (\Throwable $e) {
                $order->meta = array_merge($order->meta ?? [], [
                    'last_reconcile_error' => $e->getMessage(),
                    'last_reconciled_at' => now()->toIso8601String(),
                ]);
                $order->save();
            }
        }
    }
}
