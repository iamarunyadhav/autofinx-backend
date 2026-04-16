<?php

namespace App\Http\Controllers\Api;

use App\Domain\Trading\QuickTradeService;
use App\Domain\Trading\RobotSessionService;
use App\Models\RobotSession;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class RobotSessionController extends Controller
{
    public function index(string $workspaceId)
    {
        return response()->json(
            RobotSession::where('workspace_id', $workspaceId)
                ->orderByDesc('updated_at')
                ->get()
        );
    }

    public function store(Request $request, string $workspaceId, RobotSessionService $sessions)
    {
        $payload = $request->validate([
            'name' => 'required|string|max:120',
            'wallet_account_id' => 'nullable|uuid',
            'risk_profile_id' => 'nullable|uuid',
            'symbol' => 'required|string|max:32',
            'timeframe' => 'required|string|max:16',
            'expiry_seconds' => 'required|integer|min:5|max:3600',
            'indicator_set' => 'nullable|array',
            'strategy_profile' => 'nullable|string|max:32',
            'base_stake' => 'required|numeric|min:0.01',
            'profit_target' => 'nullable|numeric|min:0',
            'loss_limit' => 'nullable|numeric|min:0',
            'max_consecutive_losses' => 'nullable|integer|min:1|max:20',
            'max_active_trades' => 'nullable|integer|min:1|max:20',
            'confidence_threshold' => 'nullable|integer|min:1|max:100',
            'provider' => 'nullable|string|max:32',
            'integration_id' => 'nullable|uuid',
        ]);

        return response()->json(
            $sessions->create($workspaceId, $payload, $request->user()?->id),
            201
        );
    }

    public function update(Request $request, RobotSession $robotSession, RobotSessionService $sessions)
    {
        $payload = $request->validate([
            'name' => 'sometimes|string|max:120',
            'wallet_account_id' => 'nullable|uuid',
            'risk_profile_id' => 'nullable|uuid',
            'symbol' => 'sometimes|string|max:32',
            'timeframe' => 'sometimes|string|max:16',
            'expiry_seconds' => 'sometimes|integer|min:5|max:3600',
            'indicator_set' => 'nullable|array',
            'strategy_profile' => 'nullable|string|max:32',
            'base_stake' => 'sometimes|numeric|min:0.01',
            'profit_target' => 'nullable|numeric|min:0',
            'loss_limit' => 'nullable|numeric|min:0',
            'max_consecutive_losses' => 'nullable|integer|min:1|max:20',
            'max_active_trades' => 'nullable|integer|min:1|max:20',
            'confidence_threshold' => 'nullable|integer|min:1|max:100',
            'provider_key' => 'sometimes|string|max:32',
            'integration_id' => 'nullable|uuid',
        ]);

        return response()->json($sessions->update($robotSession, $payload));
    }

    public function start(RobotSession $robotSession, RobotSessionService $sessions)
    {
        return response()->json($sessions->start($robotSession));
    }

    public function pause(RobotSession $robotSession, RobotSessionService $sessions)
    {
        return response()->json($sessions->pause($robotSession));
    }

    public function stop(RobotSession $robotSession, RobotSessionService $sessions)
    {
        return response()->json($sessions->stop($robotSession));
    }

    public function evaluate(RobotSession $robotSession, RobotSessionService $sessions)
    {
        return response()->json($sessions->evaluateSession($robotSession));
    }

    public function status(RobotSession $robotSession, RobotSessionService $sessions)
    {
        return response()->json($sessions->statusPayload($robotSession));
    }

    public function history(RobotSession $robotSession, QuickTradeService $quickTrades)
    {
        return response()->json($quickTrades->history(
            $robotSession->workspace_id,
            'robot_trade',
            $robotSession->id,
            100
        ));
    }

    public function performance(RobotSession $robotSession, RobotSessionService $sessions)
    {
        $payload = $sessions->statusPayload($robotSession);

        return response()->json([
            'session' => $payload['session'],
            'active_trades' => $payload['active_trades'],
            'recent_trades' => $payload['recent_trades'],
            'summary' => [
                'total_pnl' => (float) $payload['session']->total_pnl,
                'win_count' => (int) $payload['session']->win_count,
                'loss_count' => (int) $payload['session']->loss_count,
                'consecutive_losses' => (int) $payload['session']->consecutive_losses,
            ],
        ]);
    }
}
