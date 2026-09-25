<?php

namespace App\Http\Controllers\Concerns;

use App\Models\WorkspaceMember;
use Illuminate\Http\Request;

/**
 * Workspace-membership authorization for API controllers.
 *
 * This is NOT a new auth mechanism: assertWorkspaceMembership() is a verbatim
 * copy of RatioBotController::assertWorkspaceMembership() (the canonical IDOR
 * fix -- see AGENTS.md item 4), which ForexGridBotController,
 * PepperstoneBotController, AuditResearchController and
 * ServerConnectionController each already carry as a private copy. It lives
 * in a trait only because ~30 further controllers needed the identical check
 * at once; those five controllers are intentionally left untouched.
 *
 * Rules, exactly as RatioBotController applies them:
 *  - any real `workspace_members` row for (workspace, user) passes, for reads
 *    AND mutations -- RatioBotController has no role distinction;
 *  - anything else (no user, no row, empty/null workspace id) is a 403 with
 *    the same message whether or not the target resource exists.
 *
 * assertWorkspaceRole() is the narrower owner/admin gate this codebase
 * already uses for strictly higher-stakes actions
 * (FutureTradeBotController::authorizeEnvConfigChange(),
 * DeploymentReadinessService::ownerTwoFactorReady()), used here only for
 * workspace membership management, where "any member" would let any member
 * promote themselves or delete the workspace.
 */
trait AssertsWorkspaceMembership
{
    protected function assertWorkspaceMembership(Request $request, ?string $workspaceId): void
    {
        $user = $request->user();

        $isMember = $user && (string) $workspaceId !== '' && WorkspaceMember::query()
            ->where('workspace_id', (string) $workspaceId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            abort(403, 'You do not have access to this workspace.');
        }
    }

    /**
     * @param  list<string>  $roles
     */
    protected function assertWorkspaceRole(Request $request, ?string $workspaceId, array $roles = ['owner', 'admin']): void
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $role = WorkspaceMember::query()
            ->where('workspace_id', (string) $workspaceId)
            ->where('user_id', $request->user()->id)
            ->value('role');

        if (! in_array((string) $role, $roles, true)) {
            abort(403, 'Your workspace role does not allow this action.');
        }
    }

    /**
     * Every workspace id the authenticated user is a member of -- used to
     * scope list endpoints/generic queries that have no single workspace.
     *
     * @return list<string>
     */
    protected function memberWorkspaceIds(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        return WorkspaceMember::query()
            ->where('user_id', $user->id)
            ->pluck('workspace_id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }
}
