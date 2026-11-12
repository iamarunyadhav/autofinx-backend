<?php

namespace App\Http\Controllers\Api;

use App\Domain\Market\MarketPressureService;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MarketPressureController extends Controller
{
    use AssertsWorkspaceMembership;

    public function show(Request $request, string $workspace, MarketPressureService $pressure)
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $data = $request->validate([
            'symbol' => ['required', 'string', 'regex:/^[A-Z0-9]{3,24}USDT$/'],
            'window' => ['nullable', 'in:1m,5m,15m'],
        ]);
        if (! config('market_pressure.enabled', true)) return response()->json(['state' => 'disabled', 'usable' => false, 'reason' => 'feature_disabled']);
        return response()->json($pressure->read((string) $data['symbol'], (string) ($data['window'] ?? '1m')));
    }
}
