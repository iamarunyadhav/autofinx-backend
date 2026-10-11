<?php

namespace App\Http\Controllers\Api;

use App\Domain\StrategyLab\StrategyLabShadowService;
use App\Domain\Webhooks\WorkspaceWebhookTokenService;
use App\Http\Controllers\Concerns\AssertsWorkspaceMembership;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class StrategyLabWebhookController extends Controller
{
    use AssertsWorkspaceMembership;

    public function __construct(
        private readonly WorkspaceWebhookTokenService $tokens,
        private readonly StrategyLabShadowService $shadow,
    ) {}

    public function ingest(Request $request, string $workspace): JsonResponse
    {
        if (! Workspace::query()->whereKey($workspace)->exists()) {
            return response()->json(['accepted' => false, 'reason' => 'workspace_not_found'], 422);
        }
        $payload = $request->json()->all() ?: $request->all();
        if (! $this->tokens->matches($request, $workspace, $payload)) {
            return response()->json(['accepted' => false, 'reason' => 'invalid_or_missing_webhook_token'], 401);
        }
        $data = validator($payload, [
            'strategy_id' => 'required|string|max:120', 'symbol' => 'required|string|max:32',
            'signal' => ['required', Rule::in(['BUY', 'SELL', 'WAIT', 'CLOSE_LONG', 'CLOSE_SHORT'])],
            'timeframe' => ['required', Rule::in(['15', '15m', '60', '1h', '240', '4h', 'D', '1D', '1d'])],
            'time_close' => 'required|integer|min:1', 'entry' => 'nullable|numeric|min:0', 'price' => 'nullable|numeric|min:0',
            'sl' => 'nullable|numeric|min:0', 'tp1' => 'nullable|numeric|min:0', 'tp2' => 'nullable|numeric|min:0',
            'strategy_order_id' => 'nullable|string|max:191',
        ])->validate();
        $signal = $this->shadow->record($workspace, $data + $payload, Carbon::now());

        return response()->json(['accepted' => true, 'executed' => false, 'shadow_only' => true, 'signal_id' => $signal->id], 202);
    }

    public function rotate(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceRole($request, $workspace);
        return response()->json($this->tokens->rotate($workspace, (string) $request->user()->id));
    }

    public function report(Request $request, string $workspace): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspace);
        $request->validate(['strategy_id' => 'nullable|string|max:120']);
        return response()->json($this->shadow->report($workspace, $request->query('strategy_id')));
    }
}
