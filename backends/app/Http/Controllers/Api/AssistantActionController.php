<?php

namespace App\Http\Controllers\Api;

use App\Domain\Assistant\ToolRegistry;
use App\Http\Controllers\Controller;
use App\Models\AssistantAction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AssistantActionController extends Controller
{
    public function index(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        return AssistantAction::when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
    }

    public function propose(Request $request, ToolRegistry $registry)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'tool_name' => 'required|string',
            'request_json' => 'array|nullable',
            'preview_json' => 'array|nullable',
            'requires_2fa' => 'boolean',
        ]);
        abort_unless($registry->isAllowed($data['tool_name']), 403, 'Tool not allowed');

        $action = AssistantAction::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $data['workspace_id'],
            'user_id' => $request->user()?->id,
            'tool_name' => $data['tool_name'],
            'request_json' => $data['request_json'] ?? [],
            'preview_json' => $data['preview_json'] ?? [],
            'requires_2fa' => $data['requires_2fa'] ?? $registry->isMutationTool($data['tool_name']),
            'status' => 'proposed',
        ]);

        return response()->json($action, 201);
    }

    public function approve(AssistantAction $action)
    {
        if ($action->status !== 'proposed') {
            return response()->json(['message' => 'Only proposed actions can be approved.'], 422);
        }

        $action->status = 'approved';
        $action->approved_at = now();
        $action->save();
        return response()->json($action);
    }

    public function execute(Request $request, AssistantAction $action, ToolRegistry $registry)
    {
        if ($action->status !== 'approved') {
            return response()->json(['message' => 'Assistant actions must be approved before execution.'], 422);
        }

        abort_unless($registry->isAllowed($action->tool_name), 403, 'Tool not allowed');

        $result = $registry->execute($action->tool_name, $action->request_json ?? [], $request->user());
        if (($result['ok'] ?? true) === false) {
            $action->status = 'failed';
            $action->preview_json = array_merge($action->preview_json ?? [], [
                'execution_error' => $result['error'] ?? 'tool_execution_failed',
            ]);
            $action->save();

            return response()->json([
                'message' => 'Assistant action execution failed.',
                'error' => $result['error'] ?? 'tool_execution_failed',
            ], (int) ($result['status_code'] ?? 422));
        }

        $action->status = 'executed';
        $action->executed_at = now();
        $action->preview_json = array_merge($action->preview_json ?? [], [
            'execution_result' => $result,
        ]);
        $action->save();

        return response()->json([
            'action' => $action->fresh(),
            'result' => $result,
        ]);
    }
}
