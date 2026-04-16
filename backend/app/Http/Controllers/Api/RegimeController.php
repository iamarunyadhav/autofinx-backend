<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketRegime;
use App\Models\Symbol;
use Illuminate\Http\Request;

class RegimeController extends Controller
{
    public function current(Request $request)
    {
        $symbolCode = $request->query('symbol');
        $workspaceId = $request->query('workspace_id');
        if (!$symbolCode) {
            return response()->json(['message' => 'symbol required'], 422);
        }

        $symbol = Symbol::where('symbol', $symbolCode)->first();
        if (! $symbol) {
            return response()->json(['symbol' => $symbolCode, 'regime' => null, 'meta' => []], 200);
        }

        $regime = MarketRegime::where('symbol_id', $symbol->id)
            ->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderByDesc('detected_at')
            ->first();

        if (! $regime) {
            return response()->json(['symbol' => $symbolCode, 'regime' => null, 'meta' => []], 200);
        }
        return $regime;
    }

    public function history(Request $request)
    {
        $symbolCode = $request->query('symbol');
        $workspaceId = $request->query('workspace_id');
        if (!$symbolCode) {
            return response()->json(['message' => 'symbol required'], 422);
        }

        $symbol = Symbol::where('symbol', $symbolCode)->first();
        if (! $symbol) {
            return response()->json([]);
        }

        $limit = min((int) $request->query('limit', 50), 200);

        return MarketRegime::where('symbol_id', $symbol->id)
            ->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderByDesc('detected_at')
            ->limit($limit)
            ->get();
    }
}
