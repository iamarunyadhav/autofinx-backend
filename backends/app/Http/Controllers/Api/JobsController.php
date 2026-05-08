<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ops\QueueControlService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class JobsController extends Controller
{
    public function dashboard(Request $request, QueueControlService $queues)
    {
        $limit = (int) $request->query('limit', 25);

        return response()->json($queues->dashboard(max(1, min(100, $limit))));
    }

    public function retryFailed(Request $request, QueueControlService $queues)
    {
        $data = $request->validate([
            'id' => 'required|string|max:80',
            'queue' => 'nullable|string|max:80',
        ]);

        return response()->json($queues->retryFailed($data['id'], $data['queue'] ?? null));
    }

    public function forgetFailed(Request $request, QueueControlService $queues)
    {
        $data = $request->validate([
            'id' => 'required|string|max:80',
            'queue' => 'nullable|string|max:80',
        ]);

        return response()->json($queues->forgetFailed($data['id'], $data['queue'] ?? null));
    }

    public function clearPending(Request $request, QueueControlService $queues)
    {
        $data = $request->validate([
            'queue' => 'required|string|max:80',
            'connection' => 'nullable|string|max:80',
            'confirm' => 'accepted',
        ]);

        return response()->json($queues->clearPending($data['queue'], $data['connection'] ?? null));
    }

    public function restartWorkers(QueueControlService $queues)
    {
        return response()->json($queues->restartWorkers());
    }
}
