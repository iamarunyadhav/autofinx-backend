<?php

namespace App\Domain\Trading;

use App\Models\Order;
use App\Models\OrderFill;
use Illuminate\Support\Collection;

class ExecutionQualityService
{
    /**
     * @param  iterable<OrderFill>  $fills
     */
    public function summarize(Order $order, iterable $fills): array
    {
        $fillCollection = $fills instanceof Collection ? $fills : collect($fills);
        $requestedPrice = $this->requestedPrice($order);
        $filledQty = (float) $fillCollection->sum(fn (OrderFill $fill) => (float) $fill->fill_qty);
        $fillNotional = (float) $fillCollection->sum(fn (OrderFill $fill) => (float) $fill->fill_qty * (float) $fill->fill_price);
        $avgExecutionPrice = $filledQty > 0 ? round($fillNotional / $filledQty, 8) : null;
        $feeTotal = (float) $fillCollection->sum(fn (OrderFill $fill) => (float) $fill->fee);
        $firstFillTs = $fillCollection->sortBy('fill_ts')->first()?->fill_ts;
        $lastFillTs = $fillCollection->sortByDesc('fill_ts')->first()?->fill_ts;
        $fillLatencyMs = null;
        if ($order->placed_ts && $lastFillTs) {
            $fillLatencyMs = $order->placed_ts->diffInMilliseconds($lastFillTs);
        }

        $slippagePct = null;
        if ($requestedPrice !== null && $requestedPrice > 0 && $avgExecutionPrice !== null) {
            $slippagePct = strtolower((string) $order->side) === 'sell'
                ? (($requestedPrice - $avgExecutionPrice) / $requestedPrice) * 100
                : (($avgExecutionPrice - $requestedPrice) / $requestedPrice) * 100;
            $slippagePct = round($slippagePct, 4);
        }

        return [
            'requested_price' => $requestedPrice,
            'avg_execution_price' => $avgExecutionPrice,
            'filled_qty' => round($filledQty, 8),
            'fill_count' => $fillCollection->count(),
            'fee_total' => round($feeTotal, 8),
            'slippage_pct' => $slippagePct,
            'submission_latency_ms' => (int) ($order->latency_ms ?? 0) ?: null,
            'fill_latency_ms' => $fillLatencyMs,
            'partial_fill' => strtoupper((string) $order->status) === Order::STATUS_PARTIAL || ($avgExecutionPrice !== null && $filledQty > 0 && (float) $order->requested_qty > $filledQty),
            'first_fill_at' => $firstFillTs?->toIso8601String(),
            'last_fill_at' => $lastFillTs?->toIso8601String(),
            'reject_reason' => data_get($order->raw_json, 'raw.msg') ?: data_get($order->raw_json, 'msg'),
            'cancel_reason' => data_get($order->meta, 'cancel_reason'),
        ];
    }

    private function requestedPrice(Order $order): ?float
    {
        $price = (float) ($order->limit_price ?: $order->price ?: 0);

        return $price > 0 ? $price : null;
    }
}
