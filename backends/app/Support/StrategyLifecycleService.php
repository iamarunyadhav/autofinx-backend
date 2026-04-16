<?php

namespace App\Support;

use App\Models\ExchangeConnection;
use App\Models\Notification;
use App\Models\Order;
use App\Models\StrategyInstance;
use App\Models\WorkspaceMember;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StrategyLifecycleService
{
    public function heal(): array
    {
        $summary = [
            'restarted' => [],
            'skipped' => [],
            'order_retries' => [],
        ];

        $healthyConnections = $this->healthyConnections();
        $maintenanceOver = $this->maintenanceOver();

        // Restart strategies that were paused/error during maintenance or connection downtime
        StrategyInstance::whereIn('status', ['paused', 'error', 'maintenance'])
            ->get()
            ->each(function (StrategyInstance $instance) use (&$summary, $healthyConnections, $maintenanceOver) {
                $conn = $healthyConnections->firstWhere('workspace_id', $instance->workspace_id);
                if (! $conn || ! $maintenanceOver) {
                    $summary['skipped'][] = $instance->id;
                    return;
                }

                $instance->status = 'running';
                $instance->save();
                $summary['restarted'][] = $instance->id;
                $this->notifyStrategy($instance, 'Strategy auto-restarted', 'Resumed after maintenance/connection recovery.');
            });

        // Retry failed orders (bounded)
        $retryLimit = (int) config('risk.failed_order_retry_limit', 2);
        Order::where('status', 'failed')
            ->where(function ($q) use ($retryLimit) {
                $q->whereNull('meta->retry_count')
                  ->orWhere('meta->retry_count', '<', $retryLimit);
            })
            ->limit(20)
            ->get()
            ->each(function (Order $order) use (&$summary) {
                $meta = $order->meta ?? [];
                $meta['retry_count'] = ($meta['retry_count'] ?? 0) + 1;
                $meta['retry_status'] = 'queued';
                $order->meta = $meta;
                $order->status = 'pending_retry';
                $order->save();

                $summary['order_retries'][] = [
                    'order_id' => $order->id,
                    'retry_count' => $meta['retry_count'],
                ];

                $this->notifyOrder($order, $meta['retry_count']);
            });

        return $summary;
    }

    private function healthyConnections(): Collection
    {
        $freshMinutes = (int) config('risk.connection_fresh_minutes', 15);
        $since = now()->subMinutes($freshMinutes);

        return ExchangeConnection::where('is_enabled', true)
            ->whereNotNull('last_synced_at')
            ->where('last_synced_at', '>=', $since)
            ->get();
    }

    private function maintenanceOver(): bool
    {
        $until = config('risk.maintenance_until');
        if (! $until) {
            return true;
        }
        return now()->greaterThan(\Carbon\Carbon::parse($until));
    }

    private function notifyStrategy(StrategyInstance $instance, string $title, string $body): void
    {
        $users = WorkspaceMember::where('workspace_id', $instance->workspace_id)->pluck('user_id');
        foreach ($users as $userId) {
            Notification::create([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'workspace_id' => $instance->workspace_id,
                'type' => 'strategy',
                'content' => [
                    'title' => $title,
                    'body' => $body,
                    'strategy_id' => $instance->id,
                    'status' => $instance->status,
                ],
            ]);
        }
    }

    private function notifyOrder(Order $order, int $retryCount): void
    {
        $users = WorkspaceMember::where('workspace_id', $order->workspace_id)->pluck('user_id');
        foreach ($users as $userId) {
            Notification::create([
                'id' => (string) Str::uuid(),
                'user_id' => $userId,
                'workspace_id' => $order->workspace_id,
                'type' => 'order',
                'content' => [
                    'title' => 'Order marked for retry',
                    'body' => "Order {$order->id} will be retried (attempt {$retryCount}).",
                    'order_id' => $order->id,
                    'retry_count' => $retryCount,
                ],
            ]);
        }
    }
}
