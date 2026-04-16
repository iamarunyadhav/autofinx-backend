<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trading\ExitOrchestratorService;
use App\Domain\Trading\PositionExitStateService;
use App\Domain\Trading\WorkspaceExitPolicyService;
use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\PositionExitEvent;
use App\Models\WorkspaceExitPolicy;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use RuntimeException;

class ExitAutomationController extends Controller
{
    public function __construct(
        private readonly WorkspaceExitPolicyService $policies,
        private readonly PositionExitStateService $states,
        private readonly ExitOrchestratorService $orchestrator,
    ) {
    }

    public function showPolicy(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $record = $this->policies->policyRecord($workspaceId);

        return response()->json([
            'policy' => $record,
            'effective_policy' => $this->policies->effectivePolicy($workspaceId),
        ]);
    }

    public function updatePolicy(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        try {
            $payload = $this->policies->validatePayload($request->all());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $record = $this->policies->policyRecord($workspaceId);
        $record->fill($payload + ['updated_by' => $request->user()?->id]);
        $record->save();

        return response()->json([
            'policy' => $record->fresh(),
            'effective_policy' => $this->policies->effectivePolicy($workspaceId),
        ]);
    }

    public function managedPositions(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $states = $this->states->statesForWorkspace($workspaceId)
            ->map(function ($state) {
                return [
                    'id' => $state->id,
                    'workspace_id' => $state->workspace_id,
                    'position_id' => $state->position_id,
                    'symbol' => $state->symbol,
                    'status' => $state->status,
                    'managed_mode' => $state->managed_mode,
                    'effective_state' => $state->effectiveState(),
                    'stop_loss_price' => $state->stop_loss_price,
                    'take_profit_price' => $state->take_profit_price,
                    'time_stop_at' => optional($state->time_stop_at)?->toIso8601String(),
                    'trailing_high_water_mark' => $state->trailing_high_water_mark,
                    'trailing_stop_price' => $state->trailing_stop_price,
                    'partial_progress' => $state->partial_progress,
                    'blocked_reason' => $state->blocked_reason,
                    'uncertainty_reason' => $state->uncertainty_reason,
                    'last_evaluated_at' => optional($state->last_evaluated_at)?->toIso8601String(),
                    'position' => $state->position,
                    'active_exit_order' => $state->activeExitOrder,
                ];
            })
            ->values();

        return response()->json([
            'positions' => $states,
        ]);
    }

    public function showPlan(Request $request, Position $position)
    {
        $this->assertWorkspaceAccess((string) $position->workspace_id, $request->user()?->id);

        $state = $this->states->findForPosition($position);
        if (! $state && abs((float) ($position->qty ?? $position->quantity ?? 0)) > 0) {
            $state = $this->states->reviewRequired($position, 'pre_existing_position');
        }

        abort_unless($state, 404, 'Managed exit state not found for this position.');

        return response()->json([
            'position' => $position->load('symbol'),
            'exit_state' => $state->fresh(['position.symbol', 'activeExitOrder']),
        ]);
    }

    public function events(Request $request, string $workspaceId)
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        $data = $request->validate([
            'position_id' => 'nullable|uuid',
            'event_type' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $events = PositionExitEvent::query()
            ->where('workspace_id', $workspaceId)
            ->when($data['position_id'] ?? null, fn ($query, $positionId) => $query->where('position_id', $positionId))
            ->when($data['event_type'] ?? null, fn ($query, $eventType) => $query->where('event_type', $eventType))
            ->orderByDesc('occurred_at')
            ->limit((int) ($data['limit'] ?? 100))
            ->get();

        return response()->json([
            'events' => $events,
        ]);
    }

    public function evaluate(Request $request, Position $position)
    {
        $this->assertWorkspaceAccess((string) $position->workspace_id, $request->user()?->id);
        $state = $this->requireState($position);
        $result = $this->orchestrator->evaluate($state, false, $request->user()?->id, true);

        return response()->json($result);
    }

    public function arm(Request $request, Position $position)
    {
        $this->assertWorkspaceAccess((string) $position->workspace_id, $request->user()?->id);
        $state = $this->states->armPosition($position, null, $request->user()?->id);
        $this->states->recordManualAction($state, 'position_exit.armed', $request->user()?->id);

        return response()->json([
            'exit_state' => $state,
        ]);
    }

    public function pause(Request $request, Position $position)
    {
        $this->assertWorkspaceAccess((string) $position->workspace_id, $request->user()?->id);
        $data = $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        $state = $this->states->pause(
            $this->requireState($position),
            (string) ($data['reason'] ?? 'manual_operator_pause'),
            $request->user()?->id
        );
        $this->states->recordManualAction($state, 'position_exit.paused', $request->user()?->id, [
            'reason' => $data['reason'] ?? 'manual_operator_pause',
        ]);

        return response()->json([
            'exit_state' => $state,
        ]);
    }

    public function resume(Request $request, Position $position)
    {
        $this->assertWorkspaceAccess((string) $position->workspace_id, $request->user()?->id);
        $state = $this->states->resume($this->requireState($position), $request->user()?->id);
        $this->states->recordManualAction($state, 'position_exit.resumed', $request->user()?->id);

        return response()->json([
            'exit_state' => $state,
        ]);
    }

    public function flatten(Request $request, Position $position)
    {
        $this->assertWorkspaceAccess((string) $position->workspace_id, $request->user()?->id);
        $state = $this->requireState($position);
        $result = $this->orchestrator->flatten($state, $request->user()?->id);
        $this->states->recordManualAction($state, 'position_exit.flatten', $request->user()?->id, [
            'correlation_id' => $result['correlation_id'] ?? null,
        ]);

        return response()->json($result);
    }

    private function requireState(Position $position)
    {
        $state = $this->states->findForPosition($position);
        if (! $state && abs((float) ($position->qty ?? $position->quantity ?? 0)) > 0) {
            $state = $this->states->reviewRequired($position, 'pre_existing_position');
        }

        abort_unless($state, 404, 'Managed exit state not found for this position.');

        return $state;
    }

    private function assertWorkspaceAccess(string $workspaceId, ?string $userId): void
    {
        abort_if(! $userId, 401, 'Authentication required.');

        $role = WorkspaceMember::where('workspace_id', $workspaceId)->where('user_id', $userId)->value('role');
        abort_if(! $role, 403, 'You are not a member of this workspace.');
    }
}
