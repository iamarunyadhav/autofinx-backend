<?php

namespace App\Http\Controllers\Api;

use App\Domain\Intelligence\AiContextBuilderService;
use App\Domain\Intelligence\AiDecisionAuditService;
use App\Domain\Intelligence\AiDecisionCycleService;
use App\Domain\Intelligence\AiAnomalyDetectionService;
use App\Domain\Intelligence\AiNotificationSummaryService;
use App\Domain\Intelligence\AiOperatorApprovalService;
use App\Domain\Intelligence\AiTradingStateService;
use App\Http\Controllers\Controller;
use App\Models\AiDecision;
use App\Models\AiDecisionCycle;
use App\Models\AiDecisionEvent;
use App\Models\AiTradingConfig;
use App\Models\Notification;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AiTradingController extends Controller
{
    public function showConfig(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        return response()->json($this->configForWorkspace($workspaceId));
    }

    public function updateConfig(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'enabled' => 'boolean',
            'mode' => 'required|string|in:advisor,manual_approval,restricted_auto',
            'exchange' => 'required|string|in:binance',
            'market_type' => 'required|string|in:spot',
            'provider' => 'required|string|in:heuristic,openai,gemini,anthropic,claude,null,off,disabled',
            'provider_model' => 'nullable|string|max:128',
            'allowed_symbols' => 'array|nullable',
            'allowed_symbols.*' => 'string',
            'blocked_symbols' => 'array|nullable',
            'blocked_symbols.*' => 'string',
            'max_trade_size_pct' => 'nullable|numeric|min:0.01|max:100',
            'min_confidence' => 'nullable|numeric|min:0|max:1',
            'cooldown_minutes' => 'nullable|integer|min:0|max:1440',
            'max_pending_decisions_per_symbol' => 'nullable|integer|min:0|max:50',
            'max_decisions_per_hour_per_symbol' => 'nullable|integer|min:0|max:500',
            'decision_interval_minutes' => 'nullable|integer|min:1|max:1440',
            'enabled_symbols' => 'array|nullable',
            'enabled_symbols.*' => 'string',
            'max_decisions_per_hour' => 'nullable|integer|min:0|max:5000',
            'cooldown_minutes_after_trade' => 'nullable|integer|min:0|max:1440',
            'cooldown_minutes_after_loss' => 'nullable|integer|min:0|max:1440',
            'pause_until' => 'nullable|date',
            'auto_pause_on_daily_loss' => 'boolean',
            'auto_pause_on_drawdown' => 'boolean',
            'auto_pause_on_order_failure_streak' => 'nullable|integer|min:0|max:20',
            'auto_pause_on_slippage_spike' => 'boolean',
            'auto_pause_on_reconciliation_mismatch' => 'boolean',
            'manual_resume_required' => 'boolean',
            'require_stop_loss' => 'boolean',
            'require_take_profit' => 'boolean',
            'require_time_stop' => 'boolean',
            'auto_trade_enabled' => 'boolean',
            'notes' => 'nullable|string|max:2000',
        ]);

        $existing = AiTradingConfig::where('workspace_id', $workspaceId)->first();
        $config = AiTradingConfig::updateOrCreate(
            ['workspace_id' => $workspaceId],
            array_merge($data, [
                'id' => $existing?->id ?? (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'enabled_symbols' => $this->normalizeSymbols($data['enabled_symbols'] ?? []),
                'allowed_symbols' => $this->normalizeSymbols($data['allowed_symbols'] ?? []),
                'blocked_symbols' => $this->normalizeSymbols($data['blocked_symbols'] ?? []),
                'updated_by' => $request->user()?->id,
            ])
        );

        return response()->json($config);
    }

    public function buildContext(Request $request, string $workspaceId, AiContextBuilderService $builder)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'symbol' => 'required|string',
            'integration_id' => 'nullable|uuid',
            'timeframe' => 'nullable|string',
        ]);

        $context = $builder->build($workspaceId, $data['symbol'], [
            'integration_id' => $data['integration_id'] ?? null,
            'timeframe' => $data['timeframe'] ?? '1m',
            'exchange' => 'binance',
        ]);

        return response()->json($context);
    }

    public function propose(
        Request $request,
        string $workspaceId,
        AiDecisionCycleService $cycles,
    ) {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'symbol' => 'required|string',
            'integration_id' => 'nullable|uuid',
            'timeframe' => 'nullable|string',
        ]);

        $result = $cycles->run($workspaceId, $data['symbol'], [
            'integration_id' => $data['integration_id'] ?? null,
            'timeframe' => $data['timeframe'] ?? '1m',
            'manual_trigger' => true,
            'actor_id' => $request->user()?->id,
        ]);

        return response()->json([
            'cycle' => $result['cycle'],
            'decision' => $result['decision'],
            'order' => $result['order'],
            'message' => $result['message'],
        ], 201);
    }

    public function listDecisions(Request $request, string $workspaceId, AiDecisionAuditService $audit)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $audit->expirePending($workspaceId);

        $status = $request->query('status');
        $symbol = strtoupper(trim((string) $request->query('symbol', '')));
        $limit = max(1, min(200, (int) $request->query('limit', 200)));

        $query = AiDecision::where('workspace_id', $workspaceId);
        if ($status) {
            $query->where('final_status', $status);
        }
        if ($symbol !== '') {
            $query->where('symbol', $symbol);
        }

        return $query
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function showDecision(Request $request, AiDecision $decision, AiDecisionAuditService $audit)
    {
        $this->assertWorkspaceAccess((string) $decision->workspace_id, $request->user()?->id);
        $audit->expirePending((string) $decision->workspace_id);

        return response()->json($decision->fresh());
    }

    public function listCycles(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $symbol = strtoupper(trim((string) $request->query('symbol', '')));
        $limit = max(1, min(200, (int) $request->query('limit', 100)));

        $query = AiDecisionCycle::query()->where('workspace_id', $workspaceId);
        if ($symbol !== '') {
            $query->where('symbol', $symbol);
        }

        return response()->json(
            $query->orderByDesc('started_at')->limit($limit)->get()
        );
    }

    public function state(Request $request, string $workspaceId, AiTradingStateService $state)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $config = $this->configForWorkspace($workspaceId);
        $symbol = trim((string) $request->query('symbol', ''));

        return response()->json([
            'effective' => $state->snapshot($workspaceId, $symbol !== '' ? $symbol : null, $config->toArray()),
            'states' => $state->stateRowsForWorkspace($workspaceId),
        ]);
    }

    public function notifications(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $limit = max(1, min(100, (int) $request->query('limit', 50)));

        return response()->json(
            Notification::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('type', ['ai_cycle', 'ai_trading_state', 'ai_decision'])
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
        );
    }

    public function pause(Request $request, string $workspaceId, AiTradingStateService $state, AiNotificationSummaryService $notifications)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $data = $request->validate([
            'symbol' => 'nullable|string',
            'reason' => 'nullable|string|max:255',
            'pause_until' => 'nullable|date',
        ]);

        $record = $state->pause(
            $workspaceId,
            $data['symbol'] ?? null,
            \App\Models\AiTradingState::REASON_MANUAL_OPERATOR_PAUSE,
            (string) ($data['reason'] ?? 'manual_pause'),
            $request->user()?->id,
            isset($data['pause_until']) ? Carbon::parse((string) $data['pause_until']) : null,
            true
        );

        $notifications->notify($workspaceId, 'ai_trading_state', 'AI trading paused', (string) ($record->reason_detail ?: 'manual_pause'), 'warning', [
            'symbol' => $record->symbol,
            'reason_code' => $record->reason_code,
        ]);

        return response()->json($record);
    }

    public function resume(Request $request, string $workspaceId, AiTradingStateService $state, AiNotificationSummaryService $notifications)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $data = $request->validate([
            'symbol' => 'nullable|string',
        ]);

        $record = $state->resume($workspaceId, $data['symbol'] ?? null, $request->user()?->id);
        $config = $this->configForWorkspace($workspaceId);
        if ($config->pause_until !== null) {
            $config->pause_until = null;
            $config->save();
        }

        $notifications->notify($workspaceId, 'ai_trading_state', 'AI trading resumed', 'Operator resumed AI trading.', 'success', [
            'symbol' => $record->symbol,
        ]);

        return response()->json($record);
    }

    public function runCycle(Request $request, string $workspaceId, AiDecisionCycleService $cycles)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $data = $request->validate([
            'symbol' => 'required|string',
            'integration_id' => 'nullable|uuid',
            'timeframe' => 'nullable|string',
        ]);

        $result = $cycles->run($workspaceId, $data['symbol'], [
            'integration_id' => $data['integration_id'] ?? null,
            'timeframe' => $data['timeframe'] ?? '1m',
            'manual_trigger' => true,
            'actor_id' => $request->user()?->id,
        ]);

        return response()->json($result, 201);
    }

    public function anomalies(Request $request, string $workspaceId, AiContextBuilderService $builder, AiAnomalyDetectionService $anomalies, AiTradingStateService $state)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $data = $request->validate([
            'symbol' => 'required|string',
            'integration_id' => 'nullable|uuid',
            'timeframe' => 'nullable|string',
        ]);

        $config = $this->configForWorkspace($workspaceId);
        $context = $builder->build($workspaceId, $data['symbol'], [
            'integration_id' => $data['integration_id'] ?? null,
            'timeframe' => $data['timeframe'] ?? '1m',
            'exchange' => 'binance',
        ]);
        $stateSnapshot = $state->snapshot($workspaceId, $data['symbol'], $config->toArray(), $context);

        return response()->json($anomalies->detect($workspaceId, strtoupper((string) $data['symbol']), $config->toArray(), $context, $stateSnapshot));
    }

    public function decisionEvents(Request $request, AiDecision $decision)
    {
        $this->assertWorkspaceAccess((string) $decision->workspace_id, $request->user()?->id);

        return response()->json(
            AiDecisionEvent::query()
                ->where('ai_decision_id', $decision->id)
                ->orderBy('created_at')
                ->get()
        );
    }

    public function pendingApprovals(Request $request, string $workspaceId, AiOperatorApprovalService $approvals)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);
        $approvals->expireStale($workspaceId);

        $symbol = trim((string) $request->query('symbol', ''));
        $limit = max(1, min(200, (int) $request->query('limit', 50)));

        return response()->json(
            $approvals->pendingForWorkspace($workspaceId, $symbol !== '' ? $symbol : null, $limit)
        );
    }

    public function approveWorkspaceDecision(
        Request $request,
        string $workspaceId,
        string $decisionId,
        AiOperatorApprovalService $approvals,
    ) {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'execute_now' => 'nullable|boolean',
        ]);

        try {
            $result = $approvals->approve(
                $workspaceId,
                $decisionId,
                (string) $request->user()?->id,
                (bool) ($data['execute_now'] ?? true),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'decision' => $result['decision'],
            'order' => $result['order'],
            'executed' => (bool) ($result['executed'] ?? false),
            'message' => $result['message'],
        ]);
    }

    public function rejectWorkspaceDecision(
        Request $request,
        string $workspaceId,
        string $decisionId,
        AiOperatorApprovalService $approvals,
    ) {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        try {
            $result = $approvals->reject(
                $workspaceId,
                $decisionId,
                (string) $request->user()?->id,
                (string) $data['reason'],
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'decision' => $result['decision'],
            'message' => $result['message'],
        ]);
    }

    public function approveDecision(
        Request $request,
        AiDecision $decision,
        AiOperatorApprovalService $approvals,
    ) {
        return $this->approveWorkspaceDecision($request, (string) $decision->workspace_id, (string) $decision->id, $approvals);
    }

    public function rejectDecision(Request $request, AiDecision $decision, AiOperatorApprovalService $approvals)
    {
        $response = $this->rejectWorkspaceDecision($request, (string) $decision->workspace_id, (string) $decision->id, $approvals);
        $payload = $response->getData(true);

        return response()->json($payload['decision'] ?? $payload, $response->status());
    }

    private function configForWorkspace(string $workspaceId): AiTradingConfig
    {
        return AiTradingConfig::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'id' => (string) Str::uuid(),
                'enabled' => false,
                'mode' => 'advisor',
                'exchange' => 'binance',
                'market_type' => 'spot',
                'provider' => config('intelligence.ai_trading.provider', 'heuristic'),
                'provider_model' => null,
                'enabled_symbols' => [],
                'allowed_symbols' => [],
                'blocked_symbols' => [],
                'cooldown_minutes' => 0,
                'decision_interval_minutes' => 5,
                'max_pending_decisions_per_symbol' => 1,
                'max_decisions_per_hour_per_symbol' => 12,
                'max_decisions_per_hour' => 60,
                'cooldown_minutes_after_trade' => 0,
                'cooldown_minutes_after_loss' => 0,
                'pause_until' => null,
                'auto_pause_on_daily_loss' => true,
                'auto_pause_on_drawdown' => true,
                'auto_pause_on_order_failure_streak' => 3,
                'auto_pause_on_slippage_spike' => true,
                'auto_pause_on_reconciliation_mismatch' => true,
                'manual_resume_required' => false,
                'auto_trade_enabled' => false,
                'require_stop_loss' => true,
                'require_take_profit' => true,
                'require_time_stop' => true,
            ]
        );
    }

    private function assertWorkspaceAccess(string $workspaceId, ?string $userId): void
    {
        abort_if(! $userId, 401, 'Authentication required.');

        $role = WorkspaceMember::where('workspace_id', $workspaceId)->where('user_id', $userId)->value('role');
        abort_if(! $role, 403, 'You are not a member of this workspace.');
    }

    private function normalizeSymbols(array $symbols): array
    {
        return array_values(array_filter(array_map(
            fn ($symbol) => strtoupper(trim((string) $symbol)),
            $symbols
        )));
    }
}
