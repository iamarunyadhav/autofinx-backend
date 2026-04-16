<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guardrails;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GuardrailsController extends Controller
{
    public function show(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        $record = Guardrails::find($workspaceId);
        return $record ?: response()->json(['message' => 'no guardrails'], 404);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'max_slippage_pct' => 'numeric',
            'max_spread_pct' => 'numeric',
            'max_open_orders' => 'integer',
            'loss_streak_cooldown_minutes' => 'integer',
            'max_daily_loss_pct' => 'numeric',
            'trailing_stop_pct' => 'numeric',
            'take_profit_pct' => 'numeric',
            'volatility_block_pct' => 'numeric',
            'circuit_breaker_failures' => 'integer',
        ]);

        $record = Guardrails::updateOrCreate(
            ['workspace_id' => $data['workspace_id']],
            $data + ['updated_by' => $request->user()?->id, 'id' => $data['workspace_id']]
        );

        return response()->json($record);
    }
}
