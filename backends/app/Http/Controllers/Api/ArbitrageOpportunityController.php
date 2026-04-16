<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ArbitrageOpportunity;
use Illuminate\Http\Request;

class ArbitrageOpportunityController extends Controller
{
    public function index(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        return ArbitrageOpportunity::when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderByDesc('captured_at')
            ->limit(200)
            ->get();
    }
}
