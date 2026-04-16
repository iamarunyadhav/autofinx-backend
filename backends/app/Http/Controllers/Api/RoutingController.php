<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoutingPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoutingController extends Controller
{
    public function show(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        $policy = RoutingPolicy::where('workspace_id', $workspaceId)->first();
        return $policy ?: response()->json(['message' => 'no policy'], 404);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'mode' => 'string',
            'allow_split_orders' => 'boolean',
            'max_venues_per_order' => 'integer',
            'min_balance_usd' => 'numeric',
            'enabled' => 'boolean',
        ]);

        $policy = RoutingPolicy::updateOrCreate(
            ['workspace_id' => $data['workspace_id']],
            [
                'id' => RoutingPolicy::where('workspace_id', $data['workspace_id'])->first()?->id ?? (string) Str::uuid(),
                'mode' => $data['mode'] ?? 'best_price',
                'allow_split_orders' => $data['allow_split_orders'] ?? false,
                'max_venues_per_order' => $data['max_venues_per_order'] ?? 1,
                'min_balance_usd' => $data['min_balance_usd'] ?? 0,
                'enabled' => $data['enabled'] ?? true,
            ]
        );

        return response()->json($policy);
    }
}
