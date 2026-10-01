<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ml\Shadow\LearningStatusService;
use App\Domain\Ml\Shadow\ShadowEvaluationService;
use App\Domain\Ml\Shadow\ShadowPredictionService;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only Quick Trade "Python ML Shadow" endpoints. They only read stored
 * shadow rows — no python call, no exchange call, no order path — so they can
 * never block or slow a Quick Trade request. Membership is required before
 * any status is returned, matching the fresh-dataset dashboard.
 */
final class MlShadowController extends Controller
{
    use AssertsWorkspaceMembership;

    private const SHADOW = ['mode' => 'shadow_only', 'execution' => false, 'influence' => false];

    public function prediction(Request $request, ShadowPredictionService $predictions, ShadowEvaluationService $evaluation): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'symbol' => 'required|string|max:30',
            'duration_seconds' => 'required|integer|min:1|max:604800',
        ]);
        $this->assertWorkspaceMembership($request, $data['workspace_id']);
        if (! (bool) config('ml.enabled', false)) {
            return response()->json(['enabled' => false, 'status' => 'DISABLED'] + self::SHADOW);
        }
        $symbol = $this->symbol($data['symbol']);
        if ($symbol === null) {
            return response()->json(['enabled' => true, 'status' => 'UNSUPPORTED_SYMBOL', 'supported_symbols' => array_values((array) config('ml.symbols', []))] + self::SHADOW);
        }

        return response()->json(['enabled' => true] + $predictions->payload($symbol, (int) $data['duration_seconds'], $evaluation));
    }

    public function learning(Request $request, LearningStatusService $status): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'symbol' => 'required|string|max:30',
        ]);
        $this->assertWorkspaceMembership($request, $data['workspace_id']);
        $symbol = $this->symbol($data['symbol']);
        if ($symbol === null) {
            return response()->json(['enabled' => (bool) config('ml.enabled', false), 'status' => 'UNSUPPORTED_SYMBOL', 'supported_symbols' => array_values((array) config('ml.symbols', []))] + self::SHADOW);
        }

        return response()->json($status->status($symbol));
    }

    private function symbol(string $raw): ?string
    {
        $symbol = strtoupper(str_replace(['/', '-', '_', ' '], '', $raw));

        return in_array($symbol, (array) config('ml.symbols', []), true) ? $symbol : null;
    }
}
