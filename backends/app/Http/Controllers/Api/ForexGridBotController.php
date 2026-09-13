<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RunForexGridBotEvaluationJob;
use App\Models\ForexGridBotConfig;
use App\Models\ForexGridBotEvent;
use App\Models\ForexGridBotState;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Forex Grid Trading module -- brand-new, fully independent of Ratio Bot.
 *
 * IDOR fix applied from day one: every route below that receives a
 * ForexGridBotConfig via route-model-binding calls assertBotOwnership()
 * first, which checks the SAME WorkspaceMember table
 * FutureTradeBotController::authorizeRecovery()/authorizeEnvConfigChange()
 * already use to gate workspace-scoped actions -- not a new, invented auth
 * mechanism. (RatioBotController's equivalent lifecycle routes have no such
 * check -- a real, separately-flagged gap on that controller, intentionally
 * NOT touched here per this task's zero-touch boundary.)
 */
class ForexGridBotController extends Controller
{
    /**
     * GET /workspaces/{workspace}/forex-grid-bots
     */
    public function index(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $configs = ForexGridBotConfig::where('workspace_id', $workspaceId)
            ->with('state')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json($configs);
    }

    /**
     * POST /workspaces/{workspace}/forex-grid-bots
     */
    public function store(Request $request, string $workspaceId): JsonResponse
    {
        $this->assertWorkspaceMembership($request, $workspaceId);

        $data = $this->validatedPayload($request, false);

        $config = ForexGridBotConfig::create(array_merge($data, [
            'workspace_id' => $workspaceId,
            'enabled' => false,
        ]));

        ForexGridBotState::create([
            'forex_grid_bot_config_id' => $config->id,
            'status' => ForexGridBotState::STATUS_IDLE,
        ]);

        return response()->json($config->load('state'), 201);
    }

    /**
     * GET /forex-grid-bots/{forexGridBot}
     */
    public function show(Request $request, ForexGridBotConfig $forexGridBot): JsonResponse
    {
        $this->assertBotOwnership($request, $forexGridBot);

        return response()->json($forexGridBot->load('state'));
    }

    /**
     * PUT /forex-grid-bots/{forexGridBot}
     */
    public function update(Request $request, ForexGridBotConfig $forexGridBot): JsonResponse
    {
        $this->assertBotOwnership($request, $forexGridBot);

        $data = $this->validatedPayload($request, true, $forexGridBot);
        $forexGridBot->update($data);

        return response()->json($forexGridBot->fresh('state'));
    }

    /**
     * DELETE /forex-grid-bots/{forexGridBot}
     */
    public function destroy(Request $request, ForexGridBotConfig $forexGridBot): JsonResponse
    {
        $this->assertBotOwnership($request, $forexGridBot);

        $forexGridBot->levels()->delete();
        $forexGridBot->events()->delete();
        $forexGridBot->state()?->delete();
        $forexGridBot->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * POST /forex-grid-bots/{forexGridBot}/start
     */
    public function start(Request $request, ForexGridBotConfig $forexGridBot): JsonResponse
    {
        $this->assertBotOwnership($request, $forexGridBot);

        $forexGridBot->update(['enabled' => true]);
        $state = $forexGridBot->state()->firstOrCreate([], ['status' => ForexGridBotState::STATUS_IDLE]);

        // A previously stopped/blocked/paused bot re-enabling via /start goes
        // back to idle so the next evaluation tick re-anchors Level 0 fresh.
        if ($state->status !== ForexGridBotState::STATUS_RUNNING) {
            $state->update([
                'status' => ForexGridBotState::STATUS_IDLE,
                'block_reason_code' => null,
                'block_reason_detail' => null,
            ]);
        }

        RunForexGridBotEvaluationJob::dispatch((string) $forexGridBot->id, true);

        return response()->json(['message' => 'Bot started.', 'state' => $state->fresh()]);
    }

    /**
     * POST /forex-grid-bots/{forexGridBot}/pause
     */
    public function pause(Request $request, ForexGridBotConfig $forexGridBot): JsonResponse
    {
        $this->assertBotOwnership($request, $forexGridBot);

        $forexGridBot->update(['enabled' => false]);
        $forexGridBot->state()->firstOrCreate([], ['status' => ForexGridBotState::STATUS_IDLE])
            ->update(['status' => ForexGridBotState::STATUS_PAUSED]);

        return response()->json(['message' => 'Bot paused.']);
    }

    /**
     * POST /forex-grid-bots/{forexGridBot}/stop
     */
    public function stop(Request $request, ForexGridBotConfig $forexGridBot): JsonResponse
    {
        $this->assertBotOwnership($request, $forexGridBot);

        $forexGridBot->update(['enabled' => false]);
        $forexGridBot->state()->firstOrCreate([], ['status' => ForexGridBotState::STATUS_IDLE])
            ->update(['status' => ForexGridBotState::STATUS_STOPPED]);

        return response()->json(['message' => 'Bot stopped.']);
    }

    /**
     * POST /forex-grid-bots/{forexGridBot}/kill-switch
     */
    public function killSwitch(Request $request, ForexGridBotConfig $forexGridBot): JsonResponse
    {
        $this->assertBotOwnership($request, $forexGridBot);

        $data = $request->validate(['reason' => 'nullable|string|max:255']);

        $forexGridBot->update(['enabled' => false]);
        $state = $forexGridBot->state()->firstOrCreate([], ['status' => ForexGridBotState::STATUS_IDLE]);
        $state->update([
            'status' => ForexGridBotState::STATUS_BLOCKED,
            'block_reason_code' => 'kill_switch',
            'block_reason_detail' => (string) ($data['reason'] ?? 'operator_kill_switch'),
        ]);

        ForexGridBotEvent::create([
            'forex_grid_bot_config_id' => $forexGridBot->id,
            'event_type' => ForexGridBotEvent::EVENT_KILL_SWITCH,
            'context_json' => ['reason' => (string) ($data['reason'] ?? 'operator_kill_switch')],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Kill switch activated.', 'state' => $state->fresh()]);
    }

    /**
     * GET /forex-grid-bots/{forexGridBot}/levels
     */
    public function levels(Request $request, ForexGridBotConfig $forexGridBot): JsonResponse
    {
        $this->assertBotOwnership($request, $forexGridBot);

        return response()->json(
            $forexGridBot->levels()->orderByDesc('created_at')->limit(200)->get()
        );
    }

    /**
     * GET /forex-grid-bots/{forexGridBot}/events
     */
    public function events(Request $request, ForexGridBotConfig $forexGridBot): JsonResponse
    {
        $this->assertBotOwnership($request, $forexGridBot);

        return response()->json(
            $forexGridBot->events()->orderByDesc('created_at')->limit(100)->get()
        );
    }

    // -----------------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------------

    private function assertBotOwnership(Request $request, ForexGridBotConfig $forexGridBot): void
    {
        $this->assertWorkspaceMembership($request, (string) $forexGridBot->workspace_id);
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

    private function validatedPayload(Request $request, bool $partial, ?ForexGridBotConfig $existing = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'provider' => 'nullable|string|in:oanda',
            'connection_id' => 'nullable|uuid',
            'symbol' => "{$required}|string|max:32",
            'grid_spacing_pips' => "{$required}|numeric|min:0.01|max:100000",
            'position_sizing_mode' => "{$required}|string|in:fixed,martingale,hybrid",
            'base_lot_size' => "{$required}|numeric|min:0.0001",
            'martingale_multiplier' => 'nullable|numeric|min:1.0001|max:100',
            'martingale_max_lot_size' => 'nullable|numeric|min:0.0001',
            'hybrid_martingale_level_count' => 'nullable|integer|min:1|max:100',
            'max_grid_levels' => 'nullable|integer|min:1|max:200',
            'max_total_exposure_lots' => "{$required}|numeric|min:0.0001",
            'direction_mode' => 'nullable|string|in:both,buy_only,sell_only',
            'basket_take_profit_pct' => 'nullable|numeric|min:0.01|max:100000',
            'basket_stop_loss_pct' => "{$required}|numeric|min:0.01|max:100000",
        ]);

        $this->assertSizingModeSanity($data, $existing);

        if (array_key_exists('symbol', $data)) {
            $data['symbol'] = strtoupper(trim((string) $data['symbol']));
        }

        return $data;
    }

    /**
     * §1.3 -- enforced in the controller, not the model, matching this
     * codebase's existing pattern (RatioBotController::validatedPayload() /
     * assertFuturesConfigSanity()).
     */
    private function assertSizingModeSanity(array $data, ?ForexGridBotConfig $existing): void
    {
        $mode = $data['position_sizing_mode'] ?? $existing?->position_sizing_mode;
        if ($mode === null) {
            return;
        }

        $needsMartingaleFields = in_array($mode, ['martingale', 'hybrid'], true);

        $multiplier = array_key_exists('martingale_multiplier', $data) ? $data['martingale_multiplier'] : $existing?->martingale_multiplier;
        $maxLot = array_key_exists('martingale_max_lot_size', $data) ? $data['martingale_max_lot_size'] : $existing?->martingale_max_lot_size;

        if ($needsMartingaleFields) {
            if ($multiplier === null) {
                throw ValidationException::withMessages([
                    'martingale_multiplier' => 'martingale_multiplier is required when position_sizing_mode is martingale or hybrid.',
                ]);
            }
            if ($maxLot === null) {
                throw ValidationException::withMessages([
                    'martingale_max_lot_size' => 'martingale_max_lot_size is required when position_sizing_mode is martingale or hybrid.',
                ]);
            }
        } elseif (array_key_exists('martingale_multiplier', $data) && $data['martingale_multiplier'] !== null) {
            throw ValidationException::withMessages([
                'martingale_multiplier' => 'martingale_multiplier is only allowed when position_sizing_mode is martingale or hybrid.',
            ]);
        } elseif (array_key_exists('martingale_max_lot_size', $data) && $data['martingale_max_lot_size'] !== null) {
            throw ValidationException::withMessages([
                'martingale_max_lot_size' => 'martingale_max_lot_size is only allowed when position_sizing_mode is martingale or hybrid.',
            ]);
        }

        $levelCount = array_key_exists('hybrid_martingale_level_count', $data) ? $data['hybrid_martingale_level_count'] : $existing?->hybrid_martingale_level_count;

        if ($mode === 'hybrid') {
            if ($levelCount === null) {
                throw ValidationException::withMessages([
                    'hybrid_martingale_level_count' => 'hybrid_martingale_level_count is required when position_sizing_mode is hybrid.',
                ]);
            }

            $maxGridLevels = array_key_exists('max_grid_levels', $data) ? $data['max_grid_levels'] : ($existing?->max_grid_levels ?? 8);
            if ((int) $levelCount > (int) $maxGridLevels) {
                throw ValidationException::withMessages([
                    'hybrid_martingale_level_count' => 'hybrid_martingale_level_count cannot exceed max_grid_levels.',
                ]);
            }
        } elseif (array_key_exists('hybrid_martingale_level_count', $data) && $data['hybrid_martingale_level_count'] !== null) {
            throw ValidationException::withMessages([
                'hybrid_martingale_level_count' => 'hybrid_martingale_level_count is only allowed when position_sizing_mode is hybrid.',
            ]);
        }
    }
}
