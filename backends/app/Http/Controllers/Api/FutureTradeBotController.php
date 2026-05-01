<?php

namespace App\Http\Controllers\Api;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FutureTradeBotController extends Controller
{
    public function __construct(private readonly FutureTradeBotService $bot) {}

    public function status(string $workspace): JsonResponse
    {
        return response()->json($this->bot->statusPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function dashboard(string $workspace): JsonResponse
    {
        return response()->json($this->bot->dashboardPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function start(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->start($this->bot->getOrCreateConfig($workspace))]);
    }

    public function stop(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->stop($this->bot->getOrCreateConfig($workspace))]);
    }

    public function emergencyStop(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->emergencyStop($this->bot->getOrCreateConfig($workspace))]);
    }

    public function resetPaper(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->bot->resetPaper($this->bot->getOrCreateConfig($workspace))]);
    }

    public function config(string $workspace): JsonResponse
    {
        return response()->json($this->bot->configPayload($this->bot->getOrCreateConfig($workspace)));
    }

    public function updateConfig(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'settings' => 'nullable|array',
            'algorithm_notes' => 'nullable|string|max:20000',
        ]);

        return response()->json($this->bot->updateConfig($this->bot->getOrCreateConfig($workspace), $data));
    }

    public function trades(string $workspace): JsonResponse
    {
        return response()->json($this->bot->getOrCreateConfig($workspace)->trades()->orderByDesc('created_at')->limit(100)->get());
    }

    public function logs(string $workspace): JsonResponse
    {
        return response()->json($this->bot->getOrCreateConfig($workspace)->logs()->orderByDesc('occurred_at')->limit(100)->get());
    }
}
