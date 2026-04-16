<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trading\QuickTradeService;
use App\Domain\Trading\TradingModuleSettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class QuickTradeController extends Controller
{
    public function metadata(Request $request, TradingModuleSettingsService $settings)
    {
        return response()->json($settings->metadata($request->query('workspace_id')));
    }

    public function chart(Request $request, QuickTradeService $quickTrades)
    {
        $data = $request->validate([
            'workspace_id' => 'nullable|uuid',
            'symbol' => 'required|string|max:32',
            'timeframe' => 'nullable|string|max:16',
            'chart_type' => 'nullable|string|max:24',
            'indicator_set' => 'nullable|array',
        ]);

        return response()->json($quickTrades->chart(
            $data['workspace_id'] ?? '',
            (string) $data['symbol'],
            (string) ($data['timeframe'] ?? '1m'),
            (string) ($data['chart_type'] ?? 'area'),
            (array) ($data['indicator_set'] ?? [])
        ));
    }

    public function technicalSummary(Request $request, QuickTradeService $quickTrades)
    {
        $data = $request->validate([
            'workspace_id' => 'nullable|uuid',
            'symbol' => 'required|string|max:32',
            'timeframe' => 'nullable|string|max:16',
            'indicator_set' => 'nullable|array',
        ]);

        $chart = $quickTrades->chart(
            $data['workspace_id'] ?? '',
            (string) $data['symbol'],
            (string) ($data['timeframe'] ?? '1m'),
            'area',
            (array) ($data['indicator_set'] ?? [])
        );

        return response()->json([
            'symbol' => $chart['symbol'],
            'timeframe' => $chart['timeframe'],
            'quote' => $chart['quote'],
            'signal_preview' => $chart['signal_preview'],
        ]);
    }

    public function place(Request $request, QuickTradeService $quickTrades)
    {
        $payload = $request->validate([
            'workspace_id' => 'required|uuid',
            'wallet_account_id' => 'nullable|uuid',
            'robot_session_id' => 'nullable|uuid',
            'symbol' => 'required|string|max:32',
            'side' => 'required|in:buy,sell',
            'stake_amount' => 'required|numeric|min:0.01',
            'timeframe' => 'required|string|max:16',
            'expiry_seconds' => 'required|integer|min:5|max:3600',
            'mode' => 'nullable|string|in:paper,live',
            'provider' => 'nullable|string|max:32',
            'integration_id' => 'nullable|uuid',
            'indicator_set' => 'nullable|array',
        ]);

        if (($payload['mode'] ?? 'paper') === 'live') {
            return response()->json([
                'message' => 'Live quick trades are disabled during Phase 1 execution hardening.',
            ], 422);
        }

        $trade = $quickTrades->place($payload, $request->user()?->id);

        return response()->json($trade->fresh(), 201);
    }

    public function active(Request $request, string $workspaceId, QuickTradeService $quickTrades)
    {
        return response()->json($quickTrades->active(
            $workspaceId,
            $request->query('source'),
            $request->query('robot_session_id')
        ));
    }

    public function history(Request $request, string $workspaceId, QuickTradeService $quickTrades)
    {
        return response()->json($quickTrades->history(
            $workspaceId,
            $request->query('source'),
            $request->query('robot_session_id'),
            (int) $request->query('limit', 100)
        ));
    }

    public function settleDue(Request $request, QuickTradeService $quickTrades)
    {
        return response()->json([
            'settled' => $quickTrades->settleExpired((int) $request->input('limit', 100)),
        ]);
    }
}
