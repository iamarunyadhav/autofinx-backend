<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PortfolioTarget;
use Illuminate\Http\Request;

class PortfolioTargetsController extends Controller
{
    public function show(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        $record = PortfolioTarget::find($workspaceId);
        return $record ?: response()->json(['message' => 'no targets'], 404);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'targets_json' => 'required|array',
            'rebalance_interval_minutes' => 'integer',
            'enabled' => 'boolean',
        ]);

        $record = PortfolioTarget::updateOrCreate(
            ['workspace_id' => $data['workspace_id']],
            [
                'targets_json' => $data['targets_json'],
                'rebalance_interval_minutes' => $data['rebalance_interval_minutes'] ?? 1440,
                'enabled' => $data['enabled'] ?? false,
            ]
        );

        return response()->json($record);
    }
}
