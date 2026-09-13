<?php

namespace App\Http\Controllers\Api;

use App\Domain\AuditResearch\ServerDiagnostics\DiagnosticCheckRegistry;
use App\Domain\AuditResearch\ServerDiagnostics\ServerDiagnosticsService;
use App\Http\Controllers\Controller;
use App\Models\ServerConnection;
use App\Models\WorkspaceMember;
use App\Support\ExchangeSecretVault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * IDOR-safe from day one -- same WorkspaceMember membership pattern as
 * AuditResearchController. Never serializes `credential_encrypted`: the
 * model hides it (App\Models\ServerConnection::$hidden) AND every response
 * below is built from an explicit field map that never references it, as
 * defense in depth.
 *
 * Security posture (see App\Domain\AuditResearch\ServerDiagnostics for the
 * enforcement): this only ever stores SSH login details for a restricted,
 * read-only diagnostic user the OPERATOR sets up on their own server, and
 * only ever executes one of a small, fixed, hardcoded command whitelist
 * (App\Domain\AuditResearch\ServerDiagnostics\DiagnosticCheckRegistry) --
 * never a user-supplied or workspace-supplied command string.
 */
class ServerConnectionController extends Controller
{
    public function index(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $connections = ServerConnection::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ServerConnection $c) => $this->present($c))
            ->values();

        return response()->json($connections);
    }

    public function store(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'port' => 'nullable|integer|min:1|max:65535',
            'ssh_username' => 'required|string|max:255',
            'auth_method' => 'required|string|in:key,password',
            'credential' => 'required|string',
        ]);

        $vault = app(ExchangeSecretVault::class);

        $connection = ServerConnection::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'name' => $data['name'],
            'host' => $data['host'],
            'port' => $data['port'] ?? 22,
            'ssh_username' => $data['ssh_username'],
            'auth_method' => $data['auth_method'],
            'credential_encrypted' => $vault->encrypt($data['credential']),
            'status' => ServerConnection::STATUS_UNKNOWN,
        ]);

        return response()->json($this->present($connection), 201);
    }

    public function show(Request $request, ServerConnection $connection): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $connection->workspace_id);

        return response()->json($this->present($connection));
    }

    public function update(Request $request, ServerConnection $connection): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $connection->workspace_id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'host' => 'sometimes|string|max:255',
            'port' => 'sometimes|integer|min:1|max:65535',
            'ssh_username' => 'sometimes|string|max:255',
            'auth_method' => 'sometimes|string|in:key,password',
            'credential' => 'nullable|string',
        ]);

        if (array_key_exists('credential', $data)) {
            if (! empty($data['credential'])) {
                $vault = app(ExchangeSecretVault::class);
                $data['credential_encrypted'] = $vault->encrypt($data['credential']);
            }
            unset($data['credential']);
        }

        $connection->update($data);

        return response()->json($this->present($connection->fresh()));
    }

    public function destroy(Request $request, ServerConnection $connection): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $connection->workspace_id);

        $connection->delete();

        return response()->json(['message' => 'Server connection deleted']);
    }

    public function test(Request $request, ServerConnection $connection, ServerDiagnosticsService $service): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $connection->workspace_id);

        $result = $service->testConnection($connection);

        return response()->json($result);
    }

    public function runCheck(Request $request, ServerConnection $connection, ServerDiagnosticsService $service): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $connection->workspace_id);

        $data = $request->validate([
            'check_key' => 'required|string',
        ]);

        if (! DiagnosticCheckRegistry::has($data['check_key'])) {
            return response()->json([
                'message' => 'Unknown diagnostic check key.',
                'available_checks' => DiagnosticCheckRegistry::keys(),
            ], 422);
        }

        $result = $service->runCheck($connection, $data['check_key']);

        return response()->json($result);
    }

    public function checks(Request $request, ServerConnection $connection): JsonResponse
    {
        $this->assertWorkspaceMembership($request, (string) $connection->workspace_id);

        return response()->json(['checks' => DiagnosticCheckRegistry::keys()]);
    }

    /**
     * Explicit field map -- deliberately never touches credential_encrypted,
     * independent of the model's own $hidden guard.
     */
    private function present(ServerConnection $c): array
    {
        return [
            'id' => $c->id,
            'workspace_id' => $c->workspace_id,
            'name' => $c->name,
            'host' => $c->host,
            'port' => $c->port,
            'ssh_username' => $c->ssh_username,
            'auth_method' => $c->auth_method,
            'status' => $c->status,
            'last_checked_at' => $c->last_checked_at,
            'last_check_result' => $c->last_check_result,
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];
    }

    private function assertWorkspaceMembership(Request $request, string $workspaceId): void
    {
        $user = $request->user();

        $isMember = $user && WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            abort(403, 'You do not have access to this workspace.');
        }
    }
}
