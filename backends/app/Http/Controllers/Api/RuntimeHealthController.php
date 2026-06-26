<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ops\TradingRuntimeHealthService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RuntimeHealthController extends Controller
{
    public function show(Request $request, TradingRuntimeHealthService $health)
    {
        $workspaceId = $request->query('workspace_id');

        return response()->json($health->snapshot(is_string($workspaceId) ? $workspaceId : null));
    }
}
