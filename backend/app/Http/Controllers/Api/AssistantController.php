<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantSession;
use App\Models\AuditLog;
use App\Models\Workspace;
use App\Domain\Assistant\ToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AssistantController extends Controller
{
    public function chat(Request $request)
    {
        $data = $request->validate([
            'message' => 'required|string',
            'session_id' => 'nullable|string',
            'workspace_id' => 'nullable|uuid',
        ]);
        $sessionId = $data['session_id'] ?? (string) Str::uuid();
        $workspaceId = $data['workspace_id'] ?? null;

        // Only use a workspace_id that actually exists to avoid FK violations
        if ($workspaceId && !Workspace::where('id', $workspaceId)->exists()) {
            $workspaceId = null;
        }

        AssistantSession::updateOrCreate(
            ['id' => $sessionId],
            [
                'user_id' => $request->user()->id,
                'workspace_id' => $workspaceId,
                'messages' => [
                    ['role' => 'user', 'content' => $data['message']],
                    ['role' => 'assistant', 'content' => 'This is a demo response.'],
                ],
            ]
        );

        $this->logAudit($request, 'assistant.chat', ['session' => $sessionId, 'workspace_id' => $workspaceId]);
        return response()->json([
            'session_id' => $sessionId,
            'reply' => 'This is a demo assistant reply.',
        ]);
    }

    public function functionCall(Request $request, ToolRegistry $registry)
    {
        $data = $request->validate([
            'tool' => 'required|string',
            'params' => 'array',
        ]);
        abort_unless($registry->isAllowed($data['tool']), 403, 'Tool not allowed');
        if ($registry->isMutationTool($data['tool']) && $this->twoFactorRequiredButNotConfirmed($request)) {
            return response()->json([
                'message' => 'Two-factor authentication must be enabled and confirmed for assistant mutation tools.',
            ], 403);
        }

        $this->logAudit($request, 'assistant_tool', ['tool' => $data['tool'], 'params' => $data['params'] ?? []]);

        $result = $registry->execute($data['tool'], $data['params'] ?? [], $request->user());
        if (($result['ok'] ?? true) === false) {
            $statusCode = (int) ($result['status_code'] ?? 422);
            $message = match ($result['error'] ?? 'tool_execution_failed') {
                'forbidden' => 'You are not allowed to execute this assistant tool.',
                'instance_not_found' => 'The requested strategy instance was not found.',
                'invalid_params' => 'Invalid assistant tool parameters.',
                default => 'Assistant tool execution failed.',
            };

            return response()->json([
                'tool' => $data['tool'],
                'message' => $message,
                'error' => $result['error'] ?? 'tool_execution_failed',
            ], $statusCode);
        }

        return response()->json([
            'tool' => $data['tool'],
            'result' => $result,
        ]);
    }

    private function logAudit(Request $request, string $action, array $meta = []): void
    {
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => null,
            'action' => $action,
            'actor_id' => $request->user()->id,
            'actor_type' => 'user',
            'metadata' => $meta,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function twoFactorRequiredButNotConfirmed(Request $request): bool
    {
        if (! (bool) env('ENFORCE_2FA_CRITICAL', false)) {
            return false;
        }

        $user = $request->user();
        if (! $user) {
            return true;
        }

        return empty($user->two_factor_secret) || empty($user->two_factor_confirmed_at);
    }
}
