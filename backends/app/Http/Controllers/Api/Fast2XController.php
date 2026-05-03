<?php

namespace App\Http\Controllers\Api;

use App\Domain\Fast2X\BotStateService;
use App\Domain\Fast2X\Fast2XService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class Fast2XController extends Controller
{
    public function __construct(
        private readonly Fast2XService $fast2x,
        private readonly BotStateService $states,
    ) {}

    public function status(string $workspace): JsonResponse
    {
        return response()->json($this->fast2x->statusPayload($this->fast2x->getOrCreateConfig($workspace)));
    }

    public function start(string $workspace): JsonResponse
    {
        $config = $this->fast2x->getOrCreateConfig($workspace);
        $state = $this->states->start($config);
        $this->fast2x->refreshLivePrice($config->fresh('state'));
        return response()->json(['state' => $state->fresh(), 'status' => $this->fast2x->statusPayload($config->fresh('state'))]);
    }

    public function pause(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->states->pause($this->fast2x->getOrCreateConfig($workspace))]);
    }

    public function stop(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->states->stop($this->fast2x->getOrCreateConfig($workspace))]);
    }

    public function emergencyStop(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->states->emergencyStop($this->fast2x->getOrCreateConfig($workspace))]);
    }

    public function resetPaper(string $workspace): JsonResponse
    {
        return response()->json(['state' => $this->states->resetPaper($this->fast2x->getOrCreateConfig($workspace))]);
    }

    public function config(string $workspace): JsonResponse
    {
        $config = $this->fast2x->getOrCreateConfig($workspace);
        return response()->json([
            'id' => $config->id,
            'settings' => $config->mergedSettings(),
            'paper_trading' => $config->paper_trading,
            'enable_live_trading' => $config->enable_live_trading,
            'ai_brain_enabled' => $config->ai_brain_enabled,
            'algorithm_markdown' => $config->algorithm_markdown,
            'available_symbols' => config('fast2x.available_symbols', ['BTCUSDT']),
        ]);
    }

    public function updateConfig(Request $request, string $workspace): JsonResponse
    {
        $data = $request->validate([
            'settings' => 'nullable|array',
            'enable_live_trading' => 'nullable|boolean',
            'ai_brain_enabled' => 'nullable|boolean',
            'algorithm_markdown' => 'nullable|string|max:20000',
        ]);

        return response()->json($this->fast2x->updateConfig($this->fast2x->getOrCreateConfig($workspace), $data));
    }

    public function trades(string $workspace): JsonResponse
    {
        return response()->json($this->fast2x->getOrCreateConfig($workspace)->trades()->orderByDesc('created_at')->limit(100)->get());
    }

    public function logs(string $workspace): JsonResponse
    {
        return response()->json($this->fast2x->getOrCreateConfig($workspace)->logs()->orderByDesc('occurred_at')->limit(100)->get());
    }

    public function livePrice(string $workspace): JsonResponse
    {
        return response()->json($this->fast2x->refreshLivePrice($this->fast2x->getOrCreateConfig($workspace)));
    }

    public function pnl(string $workspace): JsonResponse
    {
        return response()->json($this->fast2x->pnlPayload($this->fast2x->getOrCreateConfig($workspace)));
    }

    public function chart(string $workspace): JsonResponse
    {
        return response()->json($this->fast2x->chartPayload($this->fast2x->getOrCreateConfig($workspace)));
    }
}
