<?php

namespace App\Domain\Trading;

use App\Models\Order;
use App\Models\OrderFill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ExecutionQualityTrendService
{
    public function summary(string $workspaceId, int $windowDays = 7): array
    {
        $cutoff = now()->subDays(max(1, $windowDays));
        $orders = $this->orders($workspaceId, $cutoff);

        $total = $orders->count();
        $filled = $orders->filter(fn (Order $order) => (string) $order->status === Order::STATUS_FILLED || (float) ($order->filled_qty ?? 0) > 0)->count();
        $partial = $orders->where('status', Order::STATUS_PARTIAL)->count();
        $failed = $orders->whereIn('status', [Order::STATUS_FAILED, Order::STATUS_REJECTED, Order::STATUS_CANCELLED])->count();
        $uncertain = $orders->whereNotNull('uncertain_at')->count();
        $mismatch = $orders->where('reconciliation_state', Order::RECONCILIATION_MISMATCH)->count();
        $pending = $orders->filter(fn (Order $order) => ! in_array((string) $order->status, [Order::STATUS_FILLED, Order::STATUS_FAILED, Order::STATUS_REJECTED, Order::STATUS_CANCELLED], true))->count();

        $slippage = $this->average($orders->pluck('slippage_pct'));
        $latency = $this->average($orders->pluck('latency_ms'));
        $fillLatency = $this->average($orders->filter(fn (Order $order) => $order->placed_ts && $order->filled_ts)->map(fn (Order $order) => max(0, $order->placed_ts->diffInSeconds($order->filled_ts))));
        $fills = $this->fills($workspaceId, $cutoff)->count();

        $failureRate = $this->rate($failed, $total);
        $successRate = $this->rate($filled, $total);

        $warnings = [];
        $blockers = [];
        $state = 'healthy';

        if ($failed >= 10 || $failureRate >= 25 || $mismatch >= 8 || $uncertain >= 12) {
            $state = 'blocked';
            $blockers[] = 'execution_quality_blocked';
        } elseif ($failed >= 4 || $failureRate >= 10 || $mismatch >= 3 || $uncertain >= 5 || $slippage >= 0.75 || $latency >= 45_000) {
            $state = 'caution';
            $warnings[] = 'execution_quality_degraded';
        } elseif ($pending >= 10 || $partial >= 5 || $slippage >= 0.35 || $latency >= 20_000) {
            $state = 'caution';
            $warnings[] = 'execution_latency_or_slippage_rising';
        }

        return [
            'state' => $state,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'window_days' => $windowDays,
            'metrics' => [
                'orders' => $total,
                'fills' => $fills,
                'filled_orders' => $filled,
                'partial_orders' => $partial,
                'failed_orders' => $failed,
                'uncertain_orders' => $uncertain,
                'mismatch_orders' => $mismatch,
                'pending_orders' => $pending,
                'success_rate_pct' => $successRate,
                'failure_rate_pct' => $failureRate,
                'avg_slippage_pct' => $slippage,
                'avg_latency_ms' => $latency,
                'avg_fill_latency_seconds' => $fillLatency,
            ],
            'summary' => $this->summaryText($state, $failureRate, $slippage, $latency, $uncertain, $mismatch),
        ];
    }

    public function history(string $workspaceId, int $limit = 20): array
    {
        return [
            'snapshots' => \App\Models\OperationalTrendSnapshot::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('observed_at')
                ->limit(max(1, min(100, $limit)))
                ->get()
                ->map(fn ($snapshot) => $snapshot->toArray())
                ->values()
                ->all(),
        ];
    }

    private function orders(string $workspaceId, Carbon $cutoff): Collection
    {
        return Order::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query) use ($cutoff) {
                $query->where('created_at', '>=', $cutoff)
                    ->orWhere('placed_ts', '>=', $cutoff)
                    ->orWhere('filled_ts', '>=', $cutoff);
            })
            ->get(['id', 'status', 'reconciliation_state', 'uncertain_at', 'slippage_pct', 'latency_ms', 'placed_ts', 'filled_ts', 'filled_qty', 'created_at']);
    }

    private function fills(string $workspaceId, Carbon $cutoff): Collection
    {
        return OrderFill::query()
            ->whereHas('order', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->where('fill_ts', '>=', $cutoff)
            ->get(['id']);
    }

    private function average(Collection $values): float
    {
        $filtered = $values->filter(fn ($value) => $value !== null && $value !== '');
        if ($filtered->isEmpty()) {
            return 0.0;
        }

        return round((float) $filtered->avg(fn ($value) => (float) $value), 4);
    }

    private function rate(int $part, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($part / $total) * 100, 2);
    }

    private function summaryText(string $state, float $failureRate, float $slippage, float $latency, int $uncertain, int $mismatch): string
    {
        if ($state === 'blocked') {
            return sprintf('Execution quality is blocked: failure rate %.2f%%, uncertain %d, mismatches %d.', $failureRate, $uncertain, $mismatch);
        }

        if ($state === 'caution') {
            return sprintf('Execution quality is drifting: failure rate %.2f%%, slippage %.4f%%, latency %.0f ms.', $failureRate, $slippage, $latency);
        }

        return sprintf('Execution quality is acceptable: failure rate %.2f%%, average slippage %.4f%%.', $failureRate, $slippage);
    }
}

