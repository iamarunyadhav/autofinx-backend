<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CopyTradingProfile;
use App\Models\CopyTradeReplication;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CopyTradingController extends Controller
{
    public function profiles(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        $master = $request->query('master_instance_id');
        $q = CopyTradingProfile::query();
        if ($workspaceId) {
            $q->where('follower_workspace_id', $workspaceId);
        }
        if ($master) {
            $q->where('master_instance_id', $master);
        }
        return $q->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'master_instance_id' => 'required|uuid',
            'follower_workspace_id' => 'required|uuid',
            'allocation_type' => 'required|string',
            'allocation_value' => 'numeric',
            'max_trade_size' => 'numeric|nullable',
            'max_daily_loss' => 'numeric|nullable',
            'allowed_symbols' => 'array|nullable',
            'slippage_limit' => 'numeric|nullable',
        ]);

        $profile = CopyTradingProfile::updateOrCreate(
            [
                'master_instance_id' => $data['master_instance_id'],
                'follower_workspace_id' => $data['follower_workspace_id'],
            ],
            $data + ['id' => (string) Str::uuid()]
        );

        return response()->json($profile, 201);
    }

    public function update(Request $request, CopyTradingProfile $profile)
    {
        $data = $request->validate([
            'allocation_type' => 'sometimes|string',
            'allocation_value' => 'numeric|nullable',
            'max_trade_size' => 'numeric|nullable',
            'max_daily_loss' => 'numeric|nullable',
            'allowed_symbols' => 'array|nullable',
            'slippage_limit' => 'numeric|nullable',
            'paused' => 'boolean',
        ]);
        $profile->update($data);
        return response()->json($profile);
    }

    public function destroy(CopyTradingProfile $profile)
    {
        $profile->delete();
        return response()->json(['deleted' => true]);
    }

    public function replications(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        $q = CopyTradeReplication::query();
        if ($workspaceId) {
            $q->where('follower_workspace_id', $workspaceId);
        }
        return $q->orderByDesc('created_at')->limit(200)->get();
    }
}
