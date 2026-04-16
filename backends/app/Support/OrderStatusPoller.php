<?php

namespace App\Support;

use App\Domain\Trading\BinanceOrderSyncService;
use App\Models\Order;

class OrderStatusPoller
{
    public function __construct(private readonly BinanceOrderSyncService $sync)
    {
    }

    public function poll(int $limit = 50): array
    {
        $pending = Order::with('symbol')
            ->whereIn('status', [Order::STATUS_PENDING_PROVIDER, Order::STATUS_UNCERTAIN])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $updated = 0;
        $failed = 0;

        foreach ($pending as $order) {
            try {
                if ($this->sync->sync($order, $order->trace_id ?: null, 'manual_poll')) {
                    $updated++;
                }
            } catch (\Throwable $e) {
                $order->meta = array_merge($order->meta ?? [], [
                    'last_polled_at' => now()->toIso8601String(),
                    'last_polled_error' => $e->getMessage(),
                ]);
                $order->save();
                $failed++;
            }
        }

        return [
            'polled' => $pending->count(),
            'updated' => $updated,
            'failed' => $failed,
        ];
    }
}
