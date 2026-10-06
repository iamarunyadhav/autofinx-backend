<?php

namespace App\Http\Controllers\Api;

use App\Domain\MarketContext\Data\ContextQuery;
use App\Domain\MarketContext\SnapshotBuilder;
use App\Domain\MarketContext\Support\ProfileRegistry;
use App\Http\Controllers\Controller;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only, shadow-only view of the Market Context snapshot. Advisory data: it never gates an order. */
class MarketContextController extends Controller
{
    public function __construct(private readonly SnapshotBuilder $builder, private readonly ProfileRegistry $profiles) {}

    public function show(Request $request, string $workspaceId, string $assetClass, string $instrument): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        if (! (bool) config('market_context.enabled', false)) {
            return response()->json(['message' => 'Market context is not enabled.'], 404);
        }
        $profile = $this->profiles->find($assetClass, $instrument);
        if ($profile === null || ! ($profile['enabled'] ?? false)) {
            return response()->json(['message' => 'Unknown or disabled instrument.'], 404);
        }

        $snapshot = $this->builder->build(new ContextQuery(strtoupper($assetClass), strtoupper($instrument), now()->utc(),
            (int) config('market_context.snapshot.lookback_hours', 24) * 3600), now());

        return response()->json($snapshot->toArray());
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
