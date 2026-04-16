<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\AuditLog;
use App\Models\Guardrails;
use App\Models\RoutingPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorkspaceController extends Controller
{
    public function index(Request $request)
    {
        $workspaces = Workspace::with('members')
            ->whereHas('members', fn ($q) => $q->where('user_id', $request->user()->id))
            ->get();

        return response()->json($workspaces);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'timezone' => 'nullable|string',
        ]);

        $workspace = Workspace::create([
            'id' => (string) Str::uuid(),
            'name' => $data['name'],
            'timezone' => $data['timezone'] ?? 'Asia/Colombo',
        ]);

        WorkspaceMember::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $request->user()->id,
            'role' => 'owner',
        ]);

        $this->provisionDefaults($workspace->id, $request->user()->id);
        $this->logAudit($request, 'workspace.create', $workspace->id);
        return response()->json($workspace, 201);
    }

    public function show(Workspace $workspace)
    {
        $workspace->load('members');
        return response()->json($workspace);
    }

    public function update(Request $request, Workspace $workspace)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'timezone' => 'sometimes|string',
            'settings' => 'sometimes|array',
        ]);

        $workspace->update($data);

        $this->logAudit($request, 'workspace.update', $workspace->id, $data);
        return response()->json($workspace);
    }

    public function destroy(Workspace $workspace)
    {
        $workspace->delete();

        return response()->json(['message' => 'Workspace deleted']);
    }

    public function members(Workspace $workspace)
    {
        return response()->json(
            $workspace->members()->with('user')->get()
        );
    }

    public function addMember(Request $request, Workspace $workspace)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'role' => 'required|string',
        ]);

        $user = User::firstOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['email'],
                'password' => bcrypt(Str::random(12)),
            ]
        );

        $membership = WorkspaceMember::updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id],
            ['role' => $data['role']]
        );

        $this->logAudit($request, 'workspace.invite', $workspace->id, ['email' => $data['email']]);
        return response()->json($membership, 201);
    }

    private function logAudit(Request $request, string $action, ?string $workspaceId = null, array $meta = []): void
    {
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'action' => $action,
            'actor_id' => $request->user()->id,
            'actor_type' => 'user',
            'metadata' => $meta,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function provisionDefaults(string $workspaceId, ?string $userId = null): void
    {
        Guardrails::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'max_slippage_pct' => 1,
                'max_spread_pct' => 1,
                'max_open_orders' => 20,
                'loss_streak_cooldown_minutes' => 30,
                'max_daily_loss_pct' => 5,
                'trailing_stop_pct' => 3,
                'take_profit_pct' => 5,
                'volatility_block_pct' => 10,
                'circuit_breaker_failures' => 3,
                'updated_by' => $userId,
            ]
        );

        RoutingPolicy::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'id' => (string) Str::uuid(),
                'mode' => 'best_price',
                'allow_split_orders' => false,
                'max_venues_per_order' => 1,
                'min_balance_usd' => 50,
                'enabled' => true,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]
        );
    }
}
