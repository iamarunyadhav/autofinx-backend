<?php

namespace App\Http\Controllers\Api;

use App\Domain\Signals\SignalPublisherService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Models\Signal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Reached either by a Sanctum user or by an API token (RequireApiAccessScope).
 * Token callers are already pinned to the token's workspace by that
 * middleware; user callers must be real workspace members
 * (RatioBotController rule). Reads without a workspace_id are limited to the
 * caller's own workspaces instead of every workspace's signals.
 */
class SignalController extends Controller
{
    use AssertsWorkspaceMembership;

    public function latest(Request $request)
    {
        $symbol = $request->query('symbol');
        $workspaceId = $request->query('workspace_id');
        $q = Signal::query();
        if ($symbol) {
            $q->where('symbol', $symbol);
        }
        if ($workspaceId) {
            $q->where('workspace_id', $workspaceId);
        }
        $this->scopeToCaller($request, $q, $workspaceId);
        $signal = $q->orderByDesc('created_at')->first();
        return $signal ?? response()->json([], 204);
    }

    public function history(Request $request)
    {
        $symbol = $request->query('symbol');
        $workspaceId = $request->query('workspace_id');
        $from = $request->query('from');
        $to = $request->query('to');
        $q = Signal::query();
        if ($symbol) {
            $q->where('symbol', $symbol);
        }
        if ($workspaceId) {
            $q->where('workspace_id', $workspaceId);
        }
        if ($from) {
            $q->where('created_at', '>=', Carbon::parse($from));
        }
        if ($to) {
            $q->where('created_at', '<=', Carbon::parse($to));
        }
        $this->scopeToCaller($request, $q, $workspaceId);
        return $q->orderByDesc('created_at')->limit(500)->get();
    }

    public function publish(Request $request, SignalPublisherService $publisher)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'symbol' => 'required|string',
            'action' => 'required|string',
            'confidence' => 'numeric',
            'meta' => 'array',
        ]);
        if (! $request->attributes->get('api_token')) {
            $this->assertWorkspaceMembership($request, (string) $data['workspace_id']);
        }
        $signal = $publisher->publish(
            $data['workspace_id'],
            $data['symbol'],
            $data['action'],
            (float) ($data['confidence'] ?? 0),
            $data['meta'] ?? []
        );
        return response()->json($signal, 201);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Signal>  $query
     */
    private function scopeToCaller(Request $request, $query, mixed $workspaceId): void
    {
        $token = $request->attributes->get('api_token');
        if ($token) {
            // A workspace-bound token only ever reads its own workspace.
            if ($token->workspace_id) {
                $query->where('workspace_id', (string) $token->workspace_id);
            }

            return;
        }

        if ($workspaceId) {
            $this->assertWorkspaceMembership($request, is_string($workspaceId) ? $workspaceId : null);
        }
        $query->whereIn('workspace_id', $this->memberWorkspaceIds($request));
    }
}
