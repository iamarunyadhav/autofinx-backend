<?php

namespace App\Http\Controllers\Api;

use App\Domain\Profit\Arbitrage\ArbitrageEngine;
use App\Domain\Profit\Yield\YieldEngine;
use App\Domain\Profit\DeFi\DeFiEngine;
use App\Http\Controllers\Controller;
use App\Models\ArbitrageOpportunity;
use App\Models\YieldPosition;
use App\Models\DefiPosition;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProfitCenterController extends Controller
{
    public function arbitrage(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        return ArbitrageOpportunity::when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
    }

    public function arbitrageRun(Request $request, ArbitrageEngine $engine)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'tickers' => 'required|array',
            'threshold_pct' => 'numeric',
        ]);
        $ops = $engine->findOpportunities($data['tickers'], (float) ($data['threshold_pct'] ?? 0.2));
        $recorded = [];
        foreach ($ops as $op) {
            $recorded[] = $engine->record($data['workspace_id'], $op);
        }
        return response()->json($recorded);
    }

    public function yieldPositions(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        return YieldPosition::when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))->get();
    }

    public function yieldSubscribe(Request $request, YieldEngine $engine)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'asset' => 'string',
            'apr_bps' => 'numeric',
        ]);
        $pos = $engine->accrue($data['workspace_id'], (float) ($data['apr_bps'] ?? 800), $data['asset'] ?? 'USDT');
        return response()->json($pos);
    }

    public function defiPositions(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        return DefiPosition::when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))->get();
    }

    public function defiSimulate(Request $request, DeFiEngine $engine)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'protocol' => 'string',
            'asset' => 'string',
            'apy_bps' => 'numeric',
        ]);
        $pos = $engine->simulate($data['workspace_id'], $data['protocol'] ?? 'sim', $data['asset'] ?? 'USDC', (float) ($data['apy_bps'] ?? 500));
        return response()->json($pos);
    }
}
