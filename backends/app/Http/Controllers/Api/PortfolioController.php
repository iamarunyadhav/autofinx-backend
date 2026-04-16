<?php

namespace App\Http\Controllers\Api;

use App\Domain\Portfolio\PortfolioRecommendationService;
use App\Http\Controllers\Controller;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PortfolioController extends Controller
{
    public function summary(Request $request, string $workspaceId, PortfolioRecommendationService $service): JsonResponse
    {
        $this->assertWorkspaceAccess($workspaceId, $request->user()?->id);

        return response()->json($service->latestSnapshot($workspaceId));
    }

    private function assertWorkspaceAccess(string $workspaceId, ?string $userId): void
    {
        abort_if(! $userId, 401, 'Authentication required.');

        $role = WorkspaceMember::where('workspace_id', $workspaceId)->where('user_id', $userId)->value('role');
        abort_if(! $role, 403, 'You are not a member of this workspace.');
    }
}
