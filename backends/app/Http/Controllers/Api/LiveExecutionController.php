<?php

namespace App\Http\Controllers\Api;

use App\Domain\Exchange\BinanceSpotHealthService;
use App\Domain\Ops\OperationalBreakerService;
use App\Domain\Trading\BinanceOrderSyncService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Models\LiveExecutionEvent;
use App\Models\Order;
use App\Models\OrderFill;
use App\Models\RiskEvent;
use App\Models\ExchangeConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LiveExecutionController extends Controller
{
    use AssertsWorkspaceMembership;

    public function index(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $data = $request->validate([
            'status' => 'nullable|string',
            'symbol' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $orders = Order::query()
            ->with(['fills'])
            ->withCount(['fills', 'executionAttempts', 'executionEvents'])
            ->where('workspace_id', $workspaceId)
            ->where($this->liveOrderScope())
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['symbol'] ?? null, fn ($query, $symbol) => $query->where('asset_code', strtoupper((string) $symbol)))
            ->orderByDesc('created_at')
            ->limit((int) ($data['limit'] ?? 100))
            ->get();

        return response()->json($orders);
    }

    public function show(Request $request, Order $order)
    {
        $this->assertWorkspaceMembership($request, (string) $order->workspace_id);

        abort_unless($this->isLiveOrder($order), 404);

        $order->load(['fills', 'executionAttempts', 'executionEvents']);

        return response()->json([
            'order' => $order,
            'fills' => $order->fills,
            'attempts' => $order->executionAttempts,
            'events' => $order->executionEvents,
        ]);
    }

    public function fills(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $data = $request->validate([
            'order_id' => 'nullable|uuid',
            'symbol' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $fills = OrderFill::query()
            ->whereHas('order', function ($query) use ($workspaceId, $data): void {
                $query->where('workspace_id', $workspaceId)
                    ->where($this->liveOrderScope());
                if (! empty($data['order_id'])) {
                    $query->where('id', $data['order_id']);
                }
                if (! empty($data['symbol'])) {
                    $query->where('asset_code', strtoupper((string) $data['symbol']));
                }
            })
            ->with('order')
            ->orderByDesc('fill_ts')
            ->limit((int) ($data['limit'] ?? 100))
            ->get();

        return response()->json($fills);
    }

    public function events(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $data = $request->validate([
            'order_id' => 'nullable|uuid',
            'event_type' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $events = LiveExecutionEvent::query()
            ->where('workspace_id', $workspaceId)
            ->when($data['order_id'] ?? null, fn ($query, $orderId) => $query->where('order_id', $orderId))
            ->when($data['event_type'] ?? null, fn ($query, $type) => $query->where('event_type', $type))
            ->orderByDesc('occurred_at')
            ->limit((int) ($data['limit'] ?? 100))
            ->get();

        return response()->json($events);
    }

    public function anomalies(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $anomalies = RiskEvent::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('type', [
                'reconciliation_mismatch',
                'stale_pending_order',
                'execution_uncertain',
                'exchange_unhealthy',
            ])
            ->latest('triggered_at')
            ->limit((int) ($data['limit'] ?? 100))
            ->get();

        return response()->json($anomalies);
    }

    public function health(Request $request, string $workspaceId, OperationalBreakerService $breakers, BinanceSpotHealthService $health)
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $openStatuses = [Order::STATUS_PENDING_PROVIDER, Order::STATUS_NEW, Order::STATUS_PARTIAL, Order::STATUS_UNCERTAIN];
        $openOrders = Order::query()
            ->where('workspace_id', $workspaceId)
            ->where($this->liveOrderScope())
            ->whereIn('status', $openStatuses)
            ->get();

        $connections = ExchangeConnection::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', 'binance')
            ->where('is_active', true)
            ->get()
            ->map(fn (ExchangeConnection $connection) => [
                'connection_id' => $connection->id,
                'stream' => $breakers->streamState($connection),
            ])
            ->values();

        return response()->json([
            'provider_breaker' => $breakers->providerState($workspaceId, 'binance'),
            'stale_feed_breaker' => $breakers->staleFeedState($workspaceId),
            'rest_health' => $health->snapshot($workspaceId),
            'open_orders' => $openOrders->count(),
            'uncertain_orders' => $openOrders->where('status', Order::STATUS_UNCERTAIN)->count(),
            'stale_open_orders' => $openOrders->filter(fn (Order $order) => $order->created_at && $order->created_at->lt(now()->subMinutes(30)))->count(),
            'avg_slippage_pct_24h' => round((float) Order::query()
                ->where('workspace_id', $workspaceId)
                ->where($this->liveOrderScope())
                ->whereNotNull('slippage_pct')
                ->where('created_at', '>=', now()->subDay())
                ->avg('slippage_pct'), 4),
            'avg_submission_latency_ms_24h' => (int) round((float) Order::query()
                ->where('workspace_id', $workspaceId)
                ->where($this->liveOrderScope())
                ->whereNotNull('latency_ms')
                ->where('created_at', '>=', now()->subDay())
                ->avg('latency_ms')),
            'connections' => $connections,
        ]);
    }

    public function reconcile(Request $request, Order $order, BinanceOrderSyncService $sync)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
        ]);

        $this->assertWorkspaceMembership($request, (string) $order->workspace_id);
        abort_unless((string) $order->workspace_id === (string) $data['workspace_id'] && $this->isLiveOrder($order), 404);

        try {
            $reconciled = $sync->sync($order, $order->trace_id ?: (string) Str::uuid(), 'manual_operator_reconcile') ?? $order->fresh();
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'order' => $reconciled,
        ]);
    }

    public function syncWorkspace(Request $request, string $workspaceId, BinanceOrderSyncService $sync)
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $data = $request->validate([
            'symbol' => 'nullable|string',
            'integration_id' => 'nullable|uuid',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $synced = $sync->syncForWorkspace(
            $workspaceId,
            isset($data['symbol']) ? strtoupper((string) $data['symbol']) : null,
            $data['integration_id'] ?? null,
            (int) ($data['limit'] ?? 100),
        );

        return response()->json([
            'synced' => $synced,
        ]);
    }

    private function liveOrderScope(): \Closure
    {
        return function ($query): void {
            $query->where('meta->mode', 'live')
                ->orWhereNotNull('integration_id')
                ->orWhereNotNull('bot_id');
        };
    }

    private function isLiveOrder(Order $order): bool
    {
        return strtolower((string) data_get($order->meta, 'mode', '')) === 'live'
            || $order->integration_id !== null
            || $order->bot_id !== null;
    }
}
