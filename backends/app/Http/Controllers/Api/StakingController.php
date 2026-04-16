<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StakingPosition;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StakingController extends Controller
{
    public function index(string $workspaceId)
    {
        return response()->json(
            StakingPosition::where('workspace_id', $workspaceId)
                ->orderByDesc('created_at')
                ->get()
        );
    }

    public function simulate(Request $request, string $workspaceId)
    {
        $data = $request->validate([
            'asset' => 'required|string',
            'principal' => 'required|numeric|min:0.0001',
            'apy' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1|max:365',
        ]);

        $principal = (float) $data['principal'];
        $apy = (float) $data['apy'];
        $days = (int) $data['duration_days'];
        $dailyRate = $apy / 365 / 100;
        $projected = $principal * pow(1 + $dailyRate, $days) - $principal;

        return response()->json([
            'projected_yield' => round($projected, 8),
            'principal' => $principal,
            'apy' => $apy,
            'duration_days' => $days,
            'daily_rate' => $dailyRate,
        ]);
    }

    public function start(Request $request, string $workspaceId)
    {
        $data = $request->validate([
            'asset' => 'required|string',
            'principal' => 'required|numeric|min:0.0001',
            'apy' => 'required|numeric|min:0',
            'duration_days' => 'required|integer|min:1|max:365',
        ]);

        $position = StakingPosition::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'asset' => strtoupper($data['asset']),
            'principal' => $data['principal'],
            'apy' => $data['apy'],
            'accrued' => 0,
            'status' => 'active',
            'started_at' => now(),
            'ends_at' => now()->addDays($data['duration_days']),
            'meta' => [
                'duration_days' => $data['duration_days'],
                'projected_yield' => $data['principal'] * pow(1 + ($data['apy'] / 365 / 100), $data['duration_days']) - $data['principal'],
            ],
        ]);

        return response()->json($position, 201);
    }
}
