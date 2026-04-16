<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trading\FuturesConfigService;
use App\Domain\Trading\FuturesOrderIntentService;
use App\Domain\Trading\FuturesPositionSyncService;
use App\Domain\Trading\FuturesReadinessService;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use App\Models\Order;
use App\Models\Position;
use App\Models\RiskEvent;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use RuntimeException;

class FuturesController extends Controller
{
    public function __construct(
        private readonly FuturesConfigService $configs,
        private readonly FuturesReadinessService $readiness,
        private readonly FuturesOrderIntentService $orders,
        private readonly FuturesPositionSyncService $sync,
    ) {
    }

    public function showConfig(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        return response()->json($this->configs->record($workspaceId));
    }

    public function updateConfig(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        try {
            $payload = $this->configs->validatePayload($request->all());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $record = $this->configs->record($workspaceId);
        $record->fill($payload + ['updated_by' => $request->user()?->id]);
        $record->save();

        return response()->json($record->fresh());
    }

    public function readiness(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        return response()->json($this->readiness->state($workspaceId));
    }

    public function anomalies(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $limit = max(1, min(200, (int) $request->integer('limit', 100)));

        return response()->json(
            RiskEvent::query()
                ->where('workspace_id', $workspaceId)
                ->where('type', 'like', 'futures_%')
                ->orderByDesc('triggered_at')
                ->limit($limit)
                ->get()
        );
    }

    public function positions(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        return response()->json(
            Position::query()
                ->where('workspace_id', $workspaceId)
                ->where('market_type', 'futures')
                ->orderByDesc('updated_at')
                ->get()
        );
    }

    public function orders(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        return response()->json(
            Order::query()
                ->where('workspace_id', $workspaceId)
                ->where('meta->market_type', 'futures')
                ->orderByDesc('created_at')
                ->get()
        );
    }

    public function evaluate(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $payload = $this->validatedPayload($request);
        $config = $this->configs->effective($workspaceId);
        $integration = $this->resolveIntegration($workspaceId, $payload['integration_id'] ?? null);

        try {
            $result = $this->orders->evaluate($workspaceId, $payload + ['source' => $payload['source'] ?? 'manual_operator'], $integration, $config);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function storeOrder(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $payload = $this->validatedPayload($request);
        $config = $this->configs->effective($workspaceId);
        $integration = $this->resolveIntegration($workspaceId, $payload['integration_id'] ?? null);

        try {
            $order = $this->orders->place($workspaceId, $payload + ['source' => 'manual_operator'], $integration, $config);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'order' => $order->fresh(),
            'environment' => 'testnet',
            'market_type' => 'futures',
        ], 201);
    }

    public function sync(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $integration = $this->resolveIntegration($workspaceId, $request->input('integration_id'));
        $config = $this->configs->effective($workspaceId);

        try {
            $result = $this->sync->sync($workspaceId, $integration, $config);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'integration_id' => 'nullable|uuid',
            'symbol' => 'required|string|max:32',
            'side' => 'required|string|in:buy,sell,BUY,SELL',
            'type' => 'nullable|string|in:market,limit,MARKET,LIMIT',
            'qty' => 'required|numeric|min:0.00000001',
            'price' => 'nullable|numeric|min:0',
            'leverage' => 'required|numeric|min:1|max:125',
            'margin_mode' => 'required|string|in:isolated,cross',
            'position_mode' => 'required|string|in:one_way,hedge',
            'position_side' => 'nullable|string|in:BOTH,LONG,SHORT',
            'reduce_only' => 'nullable|boolean',
            'protective_exit' => 'nullable|boolean',
            'confidence' => 'nullable|numeric|min:0|max:1',
            'source' => 'nullable|string|max:32',
            'idempotency_key' => 'nullable|string|max:64',
            'entry_price' => 'nullable|numeric|min:0',
            'liquidation_buffer_pct' => 'nullable|numeric|min:0|max:100',
        ]);
    }

    private function resolveIntegration(string $workspaceId, ?string $integrationId): Integration
    {
        $query = Integration::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider_key', 'binance')
            ->where('status', 'active');

        if ($integrationId) {
            $query->whereKey($integrationId);
        }

        $integration = $query->latest('updated_at')->first();
        if (! $integration) {
            abort(422, 'Active Binance integration not found for this workspace.');
        }

        return $integration;
    }

    private function assertWorkspaceAccess(string $workspaceId, ?string $userId): void
    {
        abort_if(! $userId, 401, 'Authentication required.');

        $role = WorkspaceMember::where('workspace_id', $workspaceId)->where('user_id', $userId)->value('role');
        abort_if(! $role, 403, 'You are not a member of this workspace.');
    }
}
