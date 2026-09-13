<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunPepperstoneBotEvaluationJob;
use App\Models\Instrument;
use App\Models\PepperstoneBotConfig;
use App\Models\PepperstoneBotEvent;
use App\Models\PepperstoneBotState;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pepperstone Metals & Energy Trading module -- brand-new, fully
 * independent of Ratio Bot AND Forex Grid.
 *
 * IDOR-safe from day one: every route below that receives a
 * PepperstoneBotConfig via route-model-binding calls assertBotOwnership()
 * first, mirroring ForexGridBotController's exact pattern (itself modeled
 * on the same WorkspaceMember existence check FutureTradeBotController
 * uses) -- not a new, invented auth mechanism.
 */
class PepperstoneBotController extends Controller
{
    /**
     * GET /workspaces/{workspace}/pepperstone-bots
     */
    public function index(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $configs = PepperstoneBotConfig::where('workspace_id', $workspaceId)
            ->with(['state', 'instrument'])
            ->orderByDesc('updated_at')
            ->get();

        return response()->json($configs);
    }

    /**
     * POST /workspaces/{workspace}/pepperstone-bots
     */
    public function store(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $data = $this->validatedPayload($request, false);

        $config = PepperstoneBotConfig::create(array_merge($data, [
            'workspace_id' => $workspaceId,
            'enabled' => false,
        ]));

        PepperstoneBotState::create([
            'pepperstone_bot_config_id' => $config->id,
            'status' => PepperstoneBotState::STATUS_IDLE,
        ]);

        return response()->json($config->load(['state', 'instrument']), 201);
    }

    /**
     * GET /pepperstone-bots/{pepperstoneBot}
     */
    public function show(Request $request, PepperstoneBotConfig $pepperstoneBot): JsonResponse
    {
        $this->assertBotOwnership($request, $pepperstoneBot);

        return response()->json($pepperstoneBot->load(['state', 'instrument']));
    }

    /**
     * PUT /pepperstone-bots/{pepperstoneBot}
     */
    public function update(Request $request, PepperstoneBotConfig $pepperstoneBot): JsonResponse
    {
        $this->assertBotOwnership($request, $pepperstoneBot);

        $data = $this->validatedPayload($request, true, $pepperstoneBot);
        $pepperstoneBot->update($data);

        return response()->json($pepperstoneBot->fresh(['state', 'instrument']));
    }

    /**
     * DELETE /pepperstone-bots/{pepperstoneBot}
     */
    public function destroy(Request $request, PepperstoneBotConfig $pepperstoneBot): JsonResponse
    {
        $this->assertBotOwnership($request, $pepperstoneBot);

        $pepperstoneBot->positions()->delete();
        foreach ($pepperstoneBot->baskets()->get() as $basket) {
            $basket->slots()->delete();
            $basket->delete();
        }
        $pepperstoneBot->events()->delete();
        $pepperstoneBot->state()?->delete();
        $pepperstoneBot->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /pepperstone-bots/{pepperstoneBot}/start
     */
    public function start(Request $request, PepperstoneBotConfig $pepperstoneBot): JsonResponse
    {
        $this->assertBotOwnership($request, $pepperstoneBot);

        $pepperstoneBot->update(['enabled' => true]);
        $state = $pepperstoneBot->state()->firstOrCreate([], ['status' => PepperstoneBotState::STATUS_IDLE]);

        // A previously stopped/blocked/paused bot re-enabling via /start goes
        // back to idle so the next evaluation tick re-anchors Level 0 fresh.
        if ($state->status !== PepperstoneBotState::STATUS_RUNNING) {
            $state->update([
                'status' => PepperstoneBotState::STATUS_IDLE,
                'block_reason_code' => null,
                'block_reason_detail' => null,
            ]);
        }

        RunPepperstoneBotEvaluationJob::dispatch((string) $pepperstoneBot->id, true);

        return response()->json(['message' => 'Bot started.', 'state' => $state->fresh()]);
    }

    /**
     * POST /pepperstone-bots/{pepperstoneBot}/pause
     */
    public function pause(Request $request, PepperstoneBotConfig $pepperstoneBot): JsonResponse
    {
        $this->assertBotOwnership($request, $pepperstoneBot);

        $pepperstoneBot->update(['enabled' => false]);
        $pepperstoneBot->state()->firstOrCreate([], ['status' => PepperstoneBotState::STATUS_IDLE])
            ->update(['status' => PepperstoneBotState::STATUS_PAUSED]);

        return response()->json(['message' => 'Bot paused.']);
    }

    /**
     * POST /pepperstone-bots/{pepperstoneBot}/stop
     */
    public function stop(Request $request, PepperstoneBotConfig $pepperstoneBot): JsonResponse
    {
        $this->assertBotOwnership($request, $pepperstoneBot);

        $pepperstoneBot->update(['enabled' => false]);
        $pepperstoneBot->state()->firstOrCreate([], ['status' => PepperstoneBotState::STATUS_IDLE])
            ->update(['status' => PepperstoneBotState::STATUS_STOPPED]);

        return response()->json(['message' => 'Bot stopped.']);
    }

    /**
     * POST /pepperstone-bots/{pepperstoneBot}/kill-switch
     */
    public function killSwitch(Request $request, PepperstoneBotConfig $pepperstoneBot): JsonResponse
    {
        $this->assertBotOwnership($request, $pepperstoneBot);

        $data = $request->validate(['reason' => 'nullable|string|max:255']);

        $pepperstoneBot->update(['enabled' => false]);
        $state = $pepperstoneBot->state()->firstOrCreate([], ['status' => PepperstoneBotState::STATUS_IDLE]);
        $state->update([
            'status' => PepperstoneBotState::STATUS_BLOCKED,
            'block_reason_code' => 'kill_switch',
            'block_reason_detail' => (string) ($data['reason'] ?? 'operator_kill_switch'),
        ]);

        PepperstoneBotEvent::create([
            'pepperstone_bot_config_id' => $pepperstoneBot->id,
            'event_type' => PepperstoneBotEvent::EVENT_KILL_SWITCH,
            'context_json' => ['reason' => (string) ($data['reason'] ?? 'operator_kill_switch')],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Kill switch activated.', 'state' => $state->fresh()]);
    }

    /**
     * GET /pepperstone-bots/{pepperstoneBot}/positions
     */
    public function positions(Request $request, PepperstoneBotConfig $pepperstoneBot): JsonResponse
    {
        $this->assertBotOwnership($request, $pepperstoneBot);

        return response()->json(
            $pepperstoneBot->positions()->orderByDesc('created_at')->limit(200)->get()
        );
    }

    /**
     * GET /pepperstone-bots/{pepperstoneBot}/events
     */
    public function events(Request $request, PepperstoneBotConfig $pepperstoneBot): JsonResponse
    {
        $this->assertBotOwnership($request, $pepperstoneBot);

        return response()->json(
            $pepperstoneBot->events()->orderByDesc('created_at')->limit(100)->get()
        );
    }

    /**
     * GET /pepperstone-bots/{pepperstoneBot}/baskets
     *
     * Basket-aware replacement for the old flat /positions list -- exposes
     * each basket with its slots nested, per the MTF/Basket plan's §13
     * frontend requirement ("basket panel: status, slots, floating P&L").
     * /positions above is kept (unused going forward, but not removed) for
     * backward compatibility -- see the upgrade migration's docblock for
     * why pepperstone_positions itself is left in place.
     */
    public function baskets(Request $request, PepperstoneBotConfig $pepperstoneBot): JsonResponse
    {
        $this->assertBotOwnership($request, $pepperstoneBot);

        return response()->json(
            $pepperstoneBot->baskets()
                ->with('slots')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
        );
    }

    // -----------------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------------

    private function assertBotOwnership(Request $request, PepperstoneBotConfig $pepperstoneBot): void
    {
        $this->assertWorkspaceMembership($request, (string) $pepperstoneBot->workspace_id);
    }

    private function assertWorkspaceMembership(Request $request, string $workspaceId): void
    {
        $user = $request->user();

        $isMember = $user && WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isMember) {
            abort(403, 'You do not have access to this workspace.');
        }
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Basket-only validation surface (MTF/Basket plan §9/§19): NO
     * position_sizing_mode / martingale fields / hybrid_martingale_level_count
     * fields are accepted here at all -- basket configs are equal-lot only
     * (`lot_per_level`), per the plan's explicit "no martingale" rule.
     * Those columns still exist on pepperstone_bot_configs (see the
     * upgrade migration's docblock for why they were left in place rather
     * than dropped) but are simply never part of this validated payload,
     * so every new config created through this controller gets Laravel's
     * column defaults for them (position_sizing_mode='fixed', the rest
     * null) and never actually uses that old sizing path -- RunPepperstone
     * BotEvaluationJob only ever calls PositionSizingService::sizeEqualLot(),
     * which reads `lot_per_level`, not `position_sizing_mode`.
     */
    private function validatedPayload(Request $request, bool $partial, ?PepperstoneBotConfig $existing = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'instrument_id' => "{$required}|uuid|exists:instruments,id",
            'connection_id' => 'nullable|uuid',
            'lot_per_level' => "{$required}|numeric|min:0.0001",
            'max_basket_levels' => 'nullable|integer|min:1|max:200',
            'max_total_exposure_lots' => "{$required}|numeric|min:0.0001",
            'max_position_lots' => "{$required}|numeric|min:0.0001",
            'portfolio_exposure_cap_lots' => 'nullable|numeric|min:0.0001',
            'spread_limit_pips' => 'nullable|numeric|min:0.01|max:100000',
            'slippage_limit_pips' => 'nullable|numeric|min:0.01|max:100000',
            'maximum_allowed_slippage' => 'nullable|numeric|min:0',
            'v3_confidence_threshold' => 'nullable|numeric|min:0|max:1',
            'min_mtf_confidence' => 'nullable|numeric|min:0|max:1',
            'daily_loss_limit_pct' => 'nullable|numeric|min:0.01|max:100',
            'margin_safety_reserve_floor' => 'nullable|numeric|min:0',
            'basket_take_profit_pct' => 'nullable|numeric|min:0.01|max:100000',
            'basket_stop_loss_pct' => "{$required}|numeric|min:0.01|max:100000",
        ]);
    }
}
