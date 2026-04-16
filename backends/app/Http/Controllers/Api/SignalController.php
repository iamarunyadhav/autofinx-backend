<?php

namespace App\Http\Controllers\Api;

use App\Domain\Signals\SignalPublisherService;
use App\Http\Controllers\Controller;
use App\Models\Signal;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SignalController extends Controller
{
    public function latest(Request $request)
    {
        $symbol = $request->query('symbol');
        $workspaceId = $request->query('workspace_id');
        $q = Signal::query();
        if ($symbol) {
            $q->where('symbol', $symbol);
        }
        if ($workspaceId) {
            $q->where('workspace_id', $workspaceId);
        }
        $signal = $q->orderByDesc('created_at')->first();
        return $signal ?? response()->json([], 204);
    }

    public function history(Request $request)
    {
        $symbol = $request->query('symbol');
        $workspaceId = $request->query('workspace_id');
        $from = $request->query('from');
        $to = $request->query('to');
        $q = Signal::query();
        if ($symbol) {
            $q->where('symbol', $symbol);
        }
        if ($workspaceId) {
            $q->where('workspace_id', $workspaceId);
        }
        if ($from) {
            $q->where('created_at', '>=', Carbon::parse($from));
        }
        if ($to) {
            $q->where('created_at', '<=', Carbon::parse($to));
        }
        return $q->orderByDesc('created_at')->limit(500)->get();
    }

    public function publish(Request $request, SignalPublisherService $publisher)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'symbol' => 'required|string',
            'action' => 'required|string',
            'confidence' => 'numeric',
            'meta' => 'array',
        ]);
        $signal = $publisher->publish(
            $data['workspace_id'],
            $data['symbol'],
            $data['action'],
            (float) ($data['confidence'] ?? 0),
            $data['meta'] ?? []
        );
        return response()->json($signal, 201);
    }
}
