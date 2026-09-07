<?php

namespace App\Http\Controllers\Api;

use App\Domain\RatioBot\RatioBotDecisionService;
use App\Domain\RatioBot\RatioBotDiagnosticsService;
use App\Domain\RatioBot\RatioBotFixActionService;
use App\Domain\RatioBot\RatioBotRuntimeQueryService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Domain\Trading\FuturesConfigService;
use App\Http\Controllers\Controller;
use App\Jobs\BackfillMarketOhlcJob;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RatioBotController extends Controller
{
    public function __construct(
        private readonly RatioBotStateService    $stateService,
        private readonly RatioBotDecisionService $decisionService,
        private readonly RatioBotRuntimeQueryService $runtime,
        private readonly RatioBotDiagnosticsService $diagnostics,
        private readonly RatioBotFixActionService $fixActions,
        private readonly FuturesConfigService $futuresConfigs,
    ) {
    }

    /**
     * GET /ratio-bots/{config}/diagnostics — read-only, per-symbol health
     * checks: startup checklist, market feed freshness, this symbol's
     * stale-feed breaker, recent bot events, background daemon/queue
     * health, and other enabled bots sharing this symbol.
     */
    public function diagnostics(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->diagnostics->diagnose($ratioBot));
    }

    /**
     * POST /ratio-bots/{config}/diagnostics/fix — apply one whitelisted,
     * symbol-scoped remediation (see RatioBotFixActionService) and return
     * the fresh diagnose() result so the panel can show before/after
     * without a full reload.
     */
    public function applyDiagnosticsFix(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $data = $request->validate(['fix_key' => 'required|string']);

        try {
            $result = $this->fixActions->apply($ratioBot, $data['fix_key'], $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($result);
    }

    // -----------------------------------------------------------------------
    // Config CRUD
    // -----------------------------------------------------------------------

    /**
     * GET /workspaces/{workspace}/ratio-bots
     */
    public function index(string $workspaceId): JsonResponse
    {
        $configs = RatioBotConfig::where('workspace_id', $workspaceId)
            ->with('state')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json($configs);
    }

    /**
     * POST /workspaces/{workspace}/ratio-bots
     */
    public function store(Request $request, string $workspaceId): JsonResponse
    {
        $data = $this->validatedPayload($request, false, $workspaceId);

        $config = RatioBotConfig::create(array_merge($this->normalizePayload($data), [
            'workspace_id' => $workspaceId,
            'enabled'      => false,
        ]));

        // Auto-create state row
        $this->stateService->getOrCreate($config);
        BackfillMarketOhlcJob::dispatch((string) $config->id);

        return response()->json($config->load('state'), 201);
    }

    /**
     * GET /ratio-bots/{config}
     */
    public function show(RatioBotConfig $ratioBot): JsonResponse
    {
        $config = $ratioBot->load('state');

        return response()->json($config->toArray() + [
            'snapshot' => $this->runtime->snapshot($config),
        ]);
    }

    /**
     * PUT /ratio-bots/{config}
     */
    public function update(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $data = $this->validatedPayload($request, true, (string) $ratioBot->workspace_id, $ratioBot);

        $ratioBot->update($this->normalizePayload($data));
        BackfillMarketOhlcJob::dispatch((string) $ratioBot->id);

        $fresh = $ratioBot->fresh('state');

        return response()->json($fresh->toArray() + [
            'snapshot' => $this->runtime->snapshot($fresh),
        ]);
    }

    /**
     * DELETE /ratio-bots/{config}
     */
    public function destroy(RatioBotConfig $ratioBot): JsonResponse
    {
        $this->stateService->stop($ratioBot);

        // ratio_bot_states cascades via FK, but decisions/events/reconciliation
        // logs/brain snapshots don't have one — delete them explicitly so a
        // removed bot leaves no orphaned rows behind (and never gets loaded
        // by a future bot that happens to reuse the same symbol).
        DB::transaction(function () use ($ratioBot): void {
            $ratioBot->decisions()->delete();
            $ratioBot->events()->delete();
            $ratioBot->reconciliationLogs()->delete();
            $ratioBot->brainSnapshots()->delete();
            $ratioBot->delete();
        });

        return response()->json(['deleted' => true]);
    }

    // -----------------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------------

    /**
     * POST /ratio-bots/{config}/start
     */
    public function start(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $readiness = $this->runtime->startingEntryReadiness($ratioBot);
        if (! $readiness['ready']) {
            return response()->json(['message' => $readiness['reason'], 'readiness' => $readiness], 422);
        }

        $state = $this->stateService->start($ratioBot, $request->user()?->id);
        return response()->json(['state' => $state, 'readiness' => $readiness, 'message' => 'Bot started. Set initial anchor via /anchor-buy if not already set.']);
    }

    /**
     * GET /ratio-bots/{config}/readiness — pre-Start balance check, callable
     * independently so the UI can show BOT NOT READY before the user even
     * presses Start.
     */
    public function readiness(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->startingEntryReadiness($ratioBot));
    }

    /**
     * GET /ratio-bots/{config}/startup-checklist — rich, per-item Start
     * readiness (spec §3): exchange connection, wallet, market feed,
     * symbol, risk state, exchange filters.
     */
    public function startupChecklist(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->startupChecklist($ratioBot));
    }

    /**
     * GET /ratio-bots/{config}/wallet — live exchange balance for both sides
     * of the trading pair (spec §4/§52 wallet dashboard).
     */
    public function wallet(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->walletBalances($ratioBot));
    }

    /**
     * GET /ratio-bots/{config}/lots — per-lot cost-basis ledger (spec §17/§57),
     * including Stage 4 Recovery lots.
     */
    public function lots(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->lots($ratioBot));
    }

    /**
     * GET /ratio-bots/{config}/effective-feed-status — the same freshness
     * check execution gates on (spec §60-61); the UI should show this
     * instead of deriving freshness from raw price age on its own.
     */
    public function effectiveFeedStatus(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->effectiveFeedStatus($ratioBot));
    }

    /**
     * GET /ratio-bots/{config}/daily-report — per-day trade/P&L rollup for
     * Testnet observation (spec §80-81).
     */
    public function dailyReport(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->dailyReport($ratioBot));
    }

    public function recoverySlot(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json(app(\App\Domain\RatioBot\RatioBotRecoverySlotService::class)->payload($ratioBot));
    }

    /**
     * GET /ratio-bots/{config}/liquidation-guard — current liquidation
     * distance, danger level, and any shared-position siblings for a
     * futures bot (see RatioBotLiquidationGuardService).
     */
    public function liquidationGuard(RatioBotConfig $ratioBot): JsonResponse
    {
        $guardService = app(\App\Domain\RatioBot\RatioBotLiquidationGuardService::class);
        $guard = $guardService->assess($ratioBot);
        $state = $ratioBot->state;

        return response()->json($guard + [
            'bot_id' => (string) $ratioBot->id,
            'bot_name' => (string) $ratioBot->name,
            'symbol' => $ratioBot->normalizedSymbol(),
            'watch_buffer_pct' => $ratioBot->liquidationWatchBufferPct(),
            'critical_buffer_pct' => $ratioBot->liquidationCriticalBufferPct(),
            // Part C.1/C.2 -- the single most important trust signal on this
            // card: can an operator see, at a glance, that Binance itself
            // (not just this app) is watching the position.
            'exchange_stop_order' => $state?->futures_stop_order_id ? [
                'order_id' => $state->futures_stop_order_id,
                'stop_price' => $state->futures_stop_price !== null ? (float) $state->futures_stop_price : null,
            ] : null,
            'estimated_funding_cost' => $state?->estimated_funding_cost !== null ? (float) $state->estimated_funding_cost : null,
            'last_funding_rate' => $state?->last_funding_rate !== null ? (float) $state->last_funding_rate : null,
            'shared_position' => $guardService->sharedPositionSiblings($ratioBot) !== [],
            'sibling_bots' => $guardService->sharedPositionSiblings($ratioBot),
            // Part B -- small status line only, no separate card (spec §7).
            'futures_recovery' => [
                'enabled' => $ratioBot->futuresRecoveryEnabled(),
                'used' => (bool) ($state?->futures_recovery_used ?? false),
                'review_due_at' => optional($state?->futures_recovery_review_at)->toIso8601String(),
                'position_opened_at' => optional($state?->futures_position_opened_at)->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /ratio-bots/{config}/market-hours — multi-asset expansion: current
     * market-open/closed status for this bot's asset class, from the SAME
     * RatioBotMarketHoursService the risk gate itself reads, so this display
     * can never disagree with what actually blocks a trade. Always
     * `applicable: false` for crypto bots.
     */
    public function marketHours(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json(app(\App\Domain\RatioBot\RatioBotMarketHoursService::class)->status($ratioBot));
    }

    /**
     * GET /ratio-bots/{config}/trend-score — the same multi-window weighted
     * trend score the auto-start-direction gate itself reads (see
     * MultiWindowTrendScoringService), plus the latest walk-forward forecast
     * backtest stats for this bot's effective horizon (see
     * ForecastHorizonBacktestService) so the UI's "Trend Window Breakdown"
     * card can show real, honestly-computed hit-rate context alongside it.
     */
    public function trendScore(RatioBotConfig $ratioBot): JsonResponse
    {
        $score = app(\App\Domain\RatioBot\MultiWindowTrendScoringService::class)->score($ratioBot);
        $horizonSeconds = app(\App\Domain\RatioBot\RatioBotBrainService::class)->horizonSeconds($ratioBot);

        $backtest = \App\Models\RatioBotForecastBacktest::query()
            ->where('ratio_bot_config_id', $ratioBot->id)
            ->where('horizon_seconds', $horizonSeconds)
            ->first();

        return response()->json($score + [
            'bot_id' => (string) $ratioBot->id,
            'symbol' => $ratioBot->normalizedSymbol(),
            'horizon_seconds' => $horizonSeconds,
            'backtest' => $backtest ? [
                'sample_size' => (int) $backtest->sample_size,
                'hits' => (int) $backtest->hits,
                'hit_rate_pct' => $backtest->hit_rate_pct !== null ? (float) $backtest->hit_rate_pct : null,
                'avg_confidence_pct' => $backtest->avg_confidence_pct !== null ? (float) $backtest->avg_confidence_pct : null,
                'computed_at' => optional($backtest->computed_at)->toIso8601String(),
            ] : null,
        ]);
    }

    // -----------------------------------------------------------------------
    // Manual / Assisted mode (spec §51) — same decision engine as automatic
    // mode, execution just waits for an explicit operator approval.
    // -----------------------------------------------------------------------

    /**
     * GET /ratio-bots/{config}/pending-approval
     */
    public function pendingApproval(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->pendingApproval($ratioBot));
    }

    /**
     * POST /ratio-bots/{config}/decisions/{decision}/approve
     */
    public function approveDecision(Request $request, RatioBotConfig $ratioBot, RatioBotDecision $decision): JsonResponse
    {
        if ((string) $decision->ratio_bot_config_id !== (string) $ratioBot->id) {
            return response()->json(['message' => 'Decision does not belong to this bot.'], 404);
        }

        if (! (bool) data_get($decision->metadata, 'requires_manual_approval', false) || data_get($decision->metadata, 'manual_decision') !== null) {
            return response()->json(['message' => 'This suggestion is no longer pending approval.'], 422);
        }

        if (! $decision->decided_at || $decision->decided_at->lt(now()->subMinutes(10))) {
            return response()->json(['message' => 'This suggestion has expired — wait for a fresh one before approving.'], 422);
        }

        $decision->update([
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => array_merge((array) ($decision->metadata ?? []), [
                'manual_decision' => 'approved',
                'approved_by' => $request->user()?->id,
                'approved_at' => now()->toIso8601String(),
            ]),
        ]);

        app(\App\Domain\RatioBot\RatioBotExecutionService::class)->execute($ratioBot, $decision->fresh());

        return response()->json(['message' => 'Approved — sent through the same execution safety pipeline as automatic mode.', 'decision' => $decision->fresh()]);
    }

    /**
     * POST /ratio-bots/{config}/decisions/{decision}/reject
     */
    public function rejectDecision(Request $request, RatioBotConfig $ratioBot, RatioBotDecision $decision): JsonResponse
    {
        if ((string) $decision->ratio_bot_config_id !== (string) $ratioBot->id) {
            return response()->json(['message' => 'Decision does not belong to this bot.'], 404);
        }

        if (! (bool) data_get($decision->metadata, 'requires_manual_approval', false) || data_get($decision->metadata, 'manual_decision') !== null) {
            return response()->json(['message' => 'This suggestion is no longer pending approval.'], 422);
        }

        $decision->update([
            'metadata' => array_merge((array) ($decision->metadata ?? []), [
                'manual_decision' => 'rejected',
                'rejected_by' => $request->user()?->id,
                'rejected_at' => now()->toIso8601String(),
            ]),
        ]);

        return response()->json(['message' => 'Rejected.', 'decision' => $decision->fresh()]);
    }

    /**
     * POST /ratio-bots/{config}/pause
     */
    public function pause(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $reason = $request->input('reason', 'operator');
        $this->stateService->pause($ratioBot, $reason, $request->user()?->id);
        return response()->json(['message' => 'Bot paused.']);
    }

    /**
     * POST /ratio-bots/{config}/resume
     */
    public function resume(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $this->stateService->resume($ratioBot, $request->user()?->id);
        return response()->json(['message' => 'Bot resumed.', 'state' => $ratioBot->state]);
    }

    public function recalculateResume(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $state = $this->stateService->getOrCreate($ratioBot);
        if ($state->position_state !== \App\Models\RatioBotState::STATE_PAUSED) {
            return response()->json([
                'message' => 'Pause the bot before recalculating strategy settings.',
                'state' => $state,
            ], 422);
        }

        $data = $request->validate([
            'stage_buy_allocations'        => 'nullable|array|min:1|max:12',
            'stage_buy_allocations.*'      => 'numeric|min:0.0001|max:100',
            'stage_buy_drops'              => 'nullable|array|min:1|max:12',
            'stage_buy_drops.*'            => 'numeric|min:0.0001|max:100',
            'stage_sell_allocations'       => 'nullable|array|min:1|max:12',
            'stage_sell_allocations.*'     => 'numeric|min:0.0001|max:100',
            'stage_sell_rises'             => 'nullable|array|min:1|max:12',
            'stage_sell_rises.*'           => 'numeric|min:0.0001|max:100',
            'strategy_reserve_pct'         => 'nullable|numeric|min:0|max:100',
            'recovery_buy_drop_pct'        => 'nullable|numeric|min:0.0001|max:100',
            'recovery_sell_rise_pct'       => 'nullable|numeric|min:0.0001|max:100',
            'min_net_profit_bps'           => 'nullable|numeric|min:0|max:10000',
            'trigger_confirm_seconds'      => 'nullable|integer|min:0|max:86400',
            'trigger_confirm_updates'      => 'nullable|integer|min:1|max:100',
            'min_price_stability_seconds'  => 'nullable|integer|min:0|max:86400',
            'max_spike_deviation_bps'      => 'nullable|numeric|min:0|max:10000',
            'apply_mode'                   => 'nullable|string|in:next_stage,reset_current_observation',
        ]);

        $applyMode = (string) ($data['apply_mode'] ?? 'next_stage');
        unset($data['apply_mode']);
        $ratioBot->update($data);

        if ($applyMode === 'reset_current_observation') {
            $state->update([
                'pending_trigger_side' => null,
                'pending_trigger_started_at' => null,
                'pending_trigger_updates' => 0,
                'pending_trigger_ratio' => null,
                'pending_trigger_price' => null,
                'current_stage_side' => null,
                'current_stage_index' => null,
            ]);
        }

        $this->stateService->resume($ratioBot->fresh('state'), $request->user()?->id);

        return response()->json([
            'message' => 'Strategy recalculated and bot resumed. Historical lots and fills were preserved.',
            'config' => $ratioBot->fresh('state'),
        ]);
    }

    /**
     * POST /ratio-bots/{config}/stop
     */
    public function stop(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $this->stateService->stop($ratioBot, $request->user()?->id);
        return response()->json(['message' => 'Bot stopped and reset to idle.']);
    }

    // -----------------------------------------------------------------------
    // Manual anchor (initial or forced re-anchor)
    // -----------------------------------------------------------------------

    /**
     * POST /ratio-bots/{config}/anchor-buy
     *
     * Operator manually records a buy at a specific price (e.g. they bought
     * on Binance directly, or want to set the anchor without executing).
     */
    public function anchorBuy(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $data = $request->validate([
            'buy_price' => 'required|numeric|min:0.000001',
            'quantity'  => 'required|numeric|min:0.000001',
            'order_id'  => 'nullable|string|max:64',
        ]);

        $state = $this->stateService->anchorManualBuy(
            config:   $ratioBot,
            buyPrice: (float) $data['buy_price'],
            quantity: (float) $data['quantity'],
            orderId:  $data['order_id'] ?? null,
            userId:   $request->user()?->id,
        );

        return response()->json([
            'message' => 'Buy anchor set. Bot is now in HOLDING state and watching for sell trigger.',
            'state'   => $state,
        ]);
    }

    /**
     * POST /ratio-bots/{config}/anchor-sell
     *
     * Operator manually records a sell anchor. The bot becomes FLAT and the
     * next automatic action can only be BUY.
     */
    public function anchorSell(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $data = $request->validate([
            'sell_price' => 'required|numeric|min:0.000001',
            'quantity'   => 'required|numeric|min:0.000001',
            'order_id'   => 'nullable|string|max:64',
        ]);

        $state = $this->stateService->anchorManualSell(
            config:    $ratioBot,
            sellPrice: (float) $data['sell_price'],
            quantity:  (float) $data['quantity'],
            orderId:   $data['order_id'] ?? null,
            userId:    $request->user()?->id,
        );

        return response()->json([
            'message' => 'Sell anchor set. Bot is now in FLAT state and watching for buy trigger.',
            'state'   => $state,
        ]);
    }

    /**
     * POST /ratio-bots/{config}/anchor-starting-inventory
     *
     * Onboards a SELL-starting-entry bot's pre-existing coin holdings (spec
     * §2) — the operator already owns this coin, it wasn't bought through
     * the bot. Only valid once, before any fill exists, and only when
     * starting_entry=SELL.
     */
    public function anchorStartingInventory(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        if ($ratioBot->startingEntry() !== 'SELL') {
            return response()->json(['message' => 'Starting inventory can only be declared when Starting Entry is set to SELL.'], 422);
        }

        $existingState = $this->stateService->getOrCreate($ratioBot);
        if ($existingState->hasOpenPosition() || (float) ($existingState->last_buy_quantity ?? 0) > 0) {
            return response()->json(['message' => 'Bot already holds a position — starting inventory can only be declared once, before the first fill.'], 422);
        }

        $data = $request->validate([
            'cost_basis_price' => 'required|numeric|min:0.000001',
            'quantity' => 'required|numeric|min:0.000001',
        ]);

        $state = $this->stateService->anchorStartingInventory(
            config: $ratioBot,
            costBasisPrice: (float) $data['cost_basis_price'],
            quantity: (float) $data['quantity'],
            userId: $request->user()?->id,
        );

        return response()->json([
            'message' => 'Starting inventory recorded. Bot is now HOLDING and will manage normal sell stages plus the recovery reserve from this position.',
            'state' => $state,
        ]);
    }

    // -----------------------------------------------------------------------
    // State / monitoring
    // -----------------------------------------------------------------------

    /**
     * GET /ratio-bots/{config}/state
     */
    public function state(RatioBotConfig $ratioBot): JsonResponse
    {
        $state = $this->stateService->getOrCreate($ratioBot);
        return response()->json($state);
    }

    public function status(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json([
            'symbol' => $ratioBot->normalizedSymbol(),
            'market_type' => $ratioBot->marketType(),
            'timeframe' => $ratioBot->timeFrame(),
            'algorithm' => $ratioBot->algorithmKey(),
            'bot_status' => $ratioBot->state?->position_state,
            'feed_status' => $ratioBot->state?->feed_status,
            'last_signal' => $ratioBot->state?->last_signal,
            'last_signal_at' => optional($ratioBot->state?->last_signal_at)->toIso8601String(),
            'last_order_status' => $ratioBot->state?->last_order_status,
            'last_fill_price' => $ratioBot->state?->last_fill_price,
            'current_position_side' => $ratioBot->state?->last_position_side,
            'current_position_qty' => $ratioBot->state?->last_position_qty,
            'unrealized_pnl' => $ratioBot->state?->unrealized_pnl,
            'strategy_health_status' => $ratioBot->state?->strategy_health_status,
            'market_regime' => $ratioBot->state?->market_regime,
            'range_status' => $ratioBot->state?->range_status,
            'current_stage_side' => $ratioBot->state?->current_stage_side,
            'current_stage_index' => $ratioBot->state?->current_stage_index,
            'used_buy_allocation_pct' => $ratioBot->state?->used_buy_allocation_pct,
            'used_sell_allocation_pct' => $ratioBot->state?->used_sell_allocation_pct,
            'observed_low_price' => $ratioBot->state?->observed_low_price,
            'observed_high_price' => $ratioBot->state?->observed_high_price,
            'cooldown_until' => optional($ratioBot->state?->cooldown_until)->toIso8601String(),
            'kill_switch' => (bool) $ratioBot->kill_switch,
            'error_state' => $ratioBot->state?->last_runtime_error,
            // Execution Safety panel telemetry — all values already computed/persisted
            // every tick by RatioBotRealtimeEngine/RatioBotStateService, exposed here
            // read-only for the frontend guard panel + trust chain.
            'last_price_timestamp' => optional($ratioBot->state?->last_price_timestamp)->toIso8601String(),
            'last_evaluated_at' => optional($ratioBot->state?->last_evaluated_at)->toIso8601String(),
            'daily_loss_total' => $ratioBot->state?->daily_loss_total,
            'consecutive_losses' => $ratioBot->state?->consecutive_losses,
            'consecutive_order_failures' => $ratioBot->state?->consecutive_order_failures,
            'block_reason_code' => $ratioBot->state?->block_reason_code,
            'block_reason_detail' => $ratioBot->state?->block_reason_detail,
            'market_health' => (array) ($ratioBot->state?->runtime_meta['market_health'] ?? []) ?: null,
        ]);
    }

    /**
     * GET /ratio-bots/{config}/ratio
     *
     * Returns current ratio calculations for the operator UI.
     */
    public function currentRatio(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $price = $request->input('price');

        if ($price === null) {
            // Fetch live price
            try {
                if ($ratioBot->marketType() === 'futures') {
                    $baseUrl = config('services.binance.futures_testnet_api_url', 'https://testnet.binancefuture.com');
                    $response = Http::get(rtrim((string) $baseUrl, '/') . '/fapi/v1/premiumIndex', ['symbol' => $ratioBot->normalizedSymbol()]);
                    $price = $response->successful() ? (float) ($response->json('markPrice') ?? $response->json('price')) : null;
                } else {
                    $baseUrl = config('services.binance.api_url', 'https://api.binance.com');
                    $response = Http::get(rtrim((string) $baseUrl, '/') . '/api/v3/ticker/price', ['symbol' => $ratioBot->normalizedSymbol()]);
                    $price = $response->successful() ? (float) $response->json('price') : null;
                }
            } catch (Throwable) {
                $price = null;
            }
        }

        if (! $price) {
            return response()->json(['error' => 'Could not resolve current price. Pass ?price= explicitly.'], 422);
        }

        $ratios = $this->decisionService->computeCurrentRatios($ratioBot, (float) $price);

        return response()->json($ratios);
    }

    // -----------------------------------------------------------------------
    // On-demand evaluation
    // -----------------------------------------------------------------------

    /**
     * POST /ratio-bots/{config}/evaluate
     *
     * Trigger an immediate evaluation (dispatches the job).
     */
    public function evaluate(RatioBotConfig $ratioBot): JsonResponse
    {
        // manualTrigger=true — an operator clicking "Evaluate now" on a
        // disabled/paused bot to sanity-check config before re-enabling it
        // must actually run; RunRatioBotEvaluationJob::handle() silently
        // no-ops disabled bots unless this is true, which was returning a
        // false-positive "dispatched" response that did nothing.
        RunRatioBotEvaluationJob::dispatch($ratioBot->id, true);
        return response()->json(['message' => 'Evaluation job dispatched.', 'config_id' => $ratioBot->id]);
    }

    public function killSwitch(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $data = $request->validate([
            'kill_switch' => 'required|boolean',
            'reason' => 'nullable|string|max:255',
        ]);

        $ratioBot->update(['kill_switch' => (bool) $data['kill_switch']]);
        if ((bool) $data['kill_switch']) {
            $this->stateService->block($ratioBot, 'kill_switch', (string) ($data['reason'] ?? 'operator_kill_switch'));
        } else {
            $this->stateService->start($ratioBot, $request->user()?->id);
        }

        return response()->json([
            'kill_switch' => (bool) $ratioBot->fresh()->kill_switch,
            'state' => $ratioBot->fresh('state')->state,
        ]);
    }

    // -----------------------------------------------------------------------
    // History / audit
    // -----------------------------------------------------------------------

    /**
     * GET /ratio-bots/{config}/decisions
     */
    public function decisions(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $decisions = $ratioBot->decisions()
            ->when($request->input('decision'), fn ($q, $v) => $q->where('decision', strtoupper($v)))
            ->orderByDesc('decided_at')
            ->limit(100)
            ->get();

        return response()->json($decisions);
    }

    /**
     * GET /ratio-bots/{config}/events
     */
    public function events(RatioBotConfig $ratioBot): JsonResponse
    {
        $events = $ratioBot->events()
            ->orderByDesc('occurred_at')
            ->limit(100)
            ->get();

        return response()->json($events);
    }

    public function lastSignal(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->lastSignal($ratioBot));
    }

    public function lastOrder(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->lastOrder($ratioBot));
    }

    public function currentPosition(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->currentPosition($ratioBot));
    }

    public function pnlSummary(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->pnlSummary($ratioBot));
    }

    public function feedHealth(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->feedHealth($ratioBot));
    }

    public function candles(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $limit = max(10, min(500, (int) $request->integer('limit', 200)));

        return response()->json($this->runtime->recentCandles($ratioBot, $limit));
    }

    /**
     * SSE fallback candle feed for symbols the frontend can't subscribe to
     * directly via Binance WS (mirrors RobotSessionController::candleFeed).
     * The primary crypto path uses a direct browser WebSocket instead — see
     * useRatioBotCandleFeed.ts.
     */
    public function candleFeed(Request $request, RatioBotConfig $ratioBot): StreamedResponse
    {
        $iterations = (int) min(60, max(1, (int) $request->query('iterations', 30)));
        $intervalSeconds = (int) min(10, max(1, (int) $request->query('interval', 2)));

        $stream = function () use ($ratioBot, $iterations, $intervalSeconds) {
            for ($i = 0; $i < $iterations; $i++) {
                if (connection_aborted()) {
                    break;
                }

                // MarketOhlc stores a `ts` datetime column, not the
                // `time`/`is_closed` shape the frontend's normalizeCandle()
                // expects — map it explicitly rather than assuming callers
                // handle both shapes.
                $candles = collect($this->runtime->recentCandles($ratioBot, 200))
                    ->map(fn ($row) => [
                        'time' => $row->ts?->timestamp,
                        'open' => (float) $row->open,
                        'high' => (float) $row->high,
                        'low' => (float) $row->low,
                        'close' => (float) $row->close,
                        'volume' => (float) $row->volume,
                        'is_closed' => true,
                    ])
                    ->values()
                    ->all();
                $latest = $candles === [] ? null : $candles[array_key_last($candles)];

                echo 'data: ' . json_encode([
                    'symbol' => $ratioBot->normalizedSymbol(),
                    'timeframe' => $ratioBot->timeFrame(),
                    'candles' => $candles,
                    'current' => $latest,
                    'ts' => now()->toIso8601String(),
                ]) . "\n\n";

                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
                sleep($intervalSeconds);
            }
        };

        return response()->stream($stream, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function snapshot(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->snapshot($ratioBot));
    }

    /**
     * GET /ratio-bots/{config}/brain-snapshot
     *
     * Latest Master Brain (Robot Signal + Near-term Forecast) evaluation,
     * shaped to match Quick Trade's SignalPreview/ForecastPayload types so
     * the frontend can reuse the same cards.
     */
    public function brainSnapshot(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($this->runtime->brainSnapshot($ratioBot));
    }

    private function validatedPayload(Request $request, bool $partial = false, ?string $workspaceId = null, ?RatioBotConfig $existing = null): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $optional = $partial ? 'sometimes' : 'nullable';

        $data = $request->validate([
            'name'                         => $optional . '|string|max:120',
            'symbol'                       => "{$required}|string|max:32",
            'exchange'                     => 'nullable|string|max:32',
            'market_type'                  => $optional . '|string|in:spot,futures',
            'algorithm'                    => $optional . '|string|in:ratio_threshold,trailing_ratio,ma_crossover,ma,rsi,breakout',
            'timeframe'                    => $optional . '|string|in:1m,3m,5m,15m,1h,4h',
            'brain_enabled'                => 'nullable|boolean',
            'brain_min_confidence'         => 'nullable|integer|min:0|max:100',
            'forecast_horizon_seconds'     => 'nullable|integer|min:30|max:86400',
            'side_mode'                    => $optional . '|string|in:buy_sell,long_only,short_only,both',
            'starting_entry'               => 'nullable|string|in:BUY,SELL',
            'mode'                         => $optional . '|string|in:advisor,manual_approval,restricted_auto',
            'execution_mode'               => $optional . '|string|in:sandbox,live',
            'spot_execution_route'         => 'nullable|string|in:spot_market,convert',
            'base_quote_amount'            => $optional . '|numeric|min:0',
            'initial_entry_pct'            => 'nullable|numeric|min:0.01|max:1',
            'reserve_tranche_count'        => 'nullable|integer|min:0|max:10',
            'stage_buy_allocations'        => 'nullable|array|min:1|max:12',
            'stage_buy_allocations.*'      => 'numeric|min:0.0001|max:100',
            'stage_buy_drops'              => 'nullable|array|min:1|max:12',
            'stage_buy_drops.*'            => 'numeric|min:0.0001|max:100',
            'stage_sell_allocations'       => 'nullable|array|min:1|max:12',
            'stage_sell_allocations.*'     => 'numeric|min:0.0001|max:100',
            'stage_sell_rises'             => 'nullable|array|min:1|max:12',
            'stage_sell_rises.*'           => 'numeric|min:0.0001|max:100',
            'strategy_reserve_pct'         => 'nullable|numeric|min:0|max:100',
            'base_asset_budget'            => 'nullable|numeric|min:0',
            'quote_asset_budget'           => 'nullable|numeric|min:0',
            'order_size_mode'              => 'nullable|string|in:fixed_quote,fixed_base,percent_of_balance',
            'sell_threshold_ratio'         => 'nullable|numeric|min:0.001|max:100',
            'buy_threshold_ratio'          => 'nullable|numeric|min:-100|max:100|not_in:0',
            'allow_partial_sell'           => 'nullable|boolean',
            'allow_partial_buy'            => 'nullable|boolean',
            'partial_sell_pct'             => 'nullable|numeric|min:0.001|max:1',
            'partial_buy_pct'              => 'nullable|numeric|min:0.001|max:1',
            'evaluation_mode'              => 'nullable|string|in:polling,websocket,hybrid,tick,candle_close',
            'poll_interval_seconds'        => 'nullable|integer|min:1|max:86400',
            'cooldown_seconds_after_trade' => 'nullable|integer|min:0|max:86400',
            'fee_bps'                      => 'nullable|numeric|min:0|max:10000',
            'max_slippage_bps'             => 'nullable|numeric|min:0|max:10000',
            'max_allowed_slippage_bps'     => 'nullable|numeric|min:0|max:10000',
            'max_entry_price_drift_bps'    => 'nullable|numeric|min:0|max:10000',
            'min_net_profit_bps'           => 'nullable|numeric|min:0|max:10000',
            'max_trades_per_hour'          => 'nullable|integer|min:1|max:1000',
            'max_trades_per_day'           => 'nullable|integer|min:1|max:10000',
            'stale_feed_max_age_seconds'   => 'nullable|integer|min:1|max:86400',
            'stale_feed_timeout_seconds'   => 'nullable|integer|min:1|max:86400',
            'min_seconds_between_evaluations' => 'nullable|integer|min:1|max:3600',
            'use_advisory_filter'          => 'nullable|boolean',
            'advisory_mode'                => 'nullable|string|max:32',
            'trigger_mode'                 => 'nullable|string|in:threshold,trailing_ratio',
            'selling_loss_ratio'           => 'nullable|numeric|min:0|max:100',
            'buying_loss_ratio'            => 'nullable|numeric|min:0|max:100',
            'trigger_confirm_seconds'      => 'nullable|integer|min:0|max:86400',
            'trigger_confirm_updates'      => 'nullable|integer|min:1|max:100',
            'use_candle_close_confirmation'=> 'nullable|boolean',
            'min_price_stability_seconds'  => 'nullable|integer|min:0|max:86400',
            'max_spike_deviation_bps'      => 'nullable|numeric|min:0|max:10000',
            'daily_loss_limit_pct'         => 'nullable|numeric|min:0|max:1',
            'max_drawdown_pct'             => 'nullable|numeric|min:0|max:1',
            'max_open_orders'              => 'nullable|integer|min:1|max:10',
            'max_position_pct'             => 'nullable|numeric|min:0|max:1',
            'kill_switch_enabled'          => 'nullable|boolean',
            'kill_switch'                  => 'nullable|boolean',
            'pause_on_order_failure_streak'=> 'nullable|integer|min:1|max:20',
            'pause_on_slippage_spike'      => 'nullable|boolean',
            'pause_on_exchange_unhealthy'  => 'nullable|boolean',
            'pause_on_stale_feed'          => 'nullable|boolean',
            'require_stop_loss'            => 'nullable|boolean',
            'stop_loss_pct'                => 'nullable|numeric|min:0.001|max:1',
            'require_take_profit'          => 'nullable|boolean',
            'take_profit_pct'              => 'nullable|numeric|min:0.001|max:100',
            'require_time_stop'            => 'nullable|boolean',
            'time_stop_minutes'            => 'nullable|integer|min:1',
            'connection_id'                => 'nullable|uuid',
            'integration_id'               => 'nullable|uuid',
            'strategy_params'              => 'nullable|array',
            'leverage'                     => 'nullable|numeric|min:1|max:125',
            'margin_type'                  => 'nullable|string|in:isolated,cross',
            'position_mode'                => 'nullable|string|in:one_way,hedge',
            'reduce_only_exit_orders'      => 'nullable|boolean',
            'max_open_position_per_symbol' => 'nullable|numeric|min:0.00000001',
            'allow_reverse_signal'         => 'nullable|boolean',
            'close_on_opposite_signal'     => 'nullable|boolean',
            'risk_per_trade'               => 'nullable|numeric|min:0|max:100',
            'max_daily_loss'               => 'nullable|numeric|min:0',
            'max_consecutive_losses'       => 'nullable|integer|min:1|max:1000',
            'is_active'                    => 'nullable|boolean',
            // V2 (gated behind v2_enabled — defaults false, never auto-enabled)
            'v2_enabled'                   => 'nullable|boolean',
            'sell_tranche_count'           => 'nullable|integer|min:0|max:10',
            'initial_exit_pct'             => 'nullable|numeric|min:0.01|max:1',
            'reserve_mode'                 => 'nullable|string|in:manual,dynamic',
            'reserve_min_pct'              => 'nullable|numeric|min:0|max:1',
            'reserve_max_pct'              => 'nullable|numeric|min:0|max:1',
            'recovery_enabled'             => 'nullable|boolean',
            'recovery_slot_enabled'        => 'nullable|boolean',
            'recovery_slot_allocation_pct' => 'nullable|numeric|min:0|max:50',
            'recovery_slot_target_pct'     => 'nullable|numeric|min:0|max:100',
            'recovery_timeout_minutes'     => 'nullable|integer|min:1|max:100000',
            'recovery_buy_drop_pct'        => 'nullable|numeric|min:0.0001|max:100',
            'recovery_sell_rise_pct'       => 'nullable|numeric|min:0.0001|max:100',
            'no_trade_alert_minutes'       => 'nullable|integer|min:1|max:100000',
            'liquidation_guard_enabled'      => 'nullable|boolean',
            'liquidation_watch_buffer_pct'   => 'nullable|numeric|min:0.5|max:90',
            'liquidation_critical_buffer_pct' => 'nullable|numeric|min:0.1|max:89.99',
            'futures_recovery_enabled'         => 'nullable|boolean',
            'futures_recovery_topup_pct'       => 'nullable|numeric|min:0|max:30',
            'futures_recovery_timeout_minutes' => 'nullable|integer|min:1|max:100000',
            'futures_recovery_min_confidence'  => 'nullable|numeric|min:0|max:100',
            'recovery_lot_min_profit_ratio'    => 'nullable|numeric|min:0|max:1',
        ]);

        $this->assertFuturesConfigSanity($data, $workspaceId, $existing);

        return $data;
    }

    /**
     * Part C.4 -- config sanity guardrails, enforced at save time rather
     * than only at trade time. Only applies when the effective config (this
     * payload merged over any existing bot) is market_type=futures.
     */
    private function assertFuturesConfigSanity(array $data, ?string $workspaceId, ?RatioBotConfig $existing): void
    {
        $marketType = $data['market_type'] ?? $existing?->market_type ?? 'spot';
        if ($marketType !== 'futures' || $workspaceId === null) {
            return;
        }

        $leverage = array_key_exists('leverage', $data) ? (float) $data['leverage'] : (float) ($existing?->leverage ?? 0);
        if ($leverage > 0) {
            $maxLeverage = (float) ($this->futuresConfigs->effective($workspaceId)['max_leverage'] ?? 0);
            if ($maxLeverage > 0 && $leverage > $maxLeverage) {
                throw ValidationException::withMessages([
                    'leverage' => "Leverage {$leverage}x exceeds this workspace's cap of {$maxLeverage}x.",
                ]);
            }
        }

        if (! (bool) ($data['liquidation_guard_enabled'] ?? $existing?->liquidation_guard_enabled ?? false)) {
            return;
        }

        $watch = array_key_exists('liquidation_watch_buffer_pct', $data)
            ? (float) $data['liquidation_watch_buffer_pct']
            : (float) ($existing?->liquidation_watch_buffer_pct ?? 15.0);
        $critical = array_key_exists('liquidation_critical_buffer_pct', $data)
            ? (float) $data['liquidation_critical_buffer_pct']
            : (float) ($existing?->liquidation_critical_buffer_pct ?? 5.0);

        // Deterministic proxy for "is this gap survivable" rather than a
        // synchronous live-volatility fetch during a config save: the
        // minimum required gap scales with leverage, since the same %
        // price move consumes buffer faster the more leveraged the
        // position is. This is a heuristic, not a live estimate -- it
        // deliberately avoids a network call inside a save-request.
        $minimumSurvivableGapPct = $leverage > 0 ? max(1.0, $leverage * 0.3) : 1.0;
        $gapPct = $watch - $critical;
        if ($gapPct < $minimumSurvivableGapPct) {
            throw ValidationException::withMessages([
                'liquidation_watch_buffer_pct' => sprintf(
                    'The gap between watch (%.2f%%) and critical (%.2f%%) buffers is only %.2f%% -- at %sx leverage this is too '
                        . 'narrow to reliably react in before the exchange liquidates. Widen the gap to at least %.2f%%.',
                    $watch,
                    $critical,
                    $gapPct,
                    rtrim(rtrim(number_format($leverage, 2), '0'), '.'),
                    $minimumSurvivableGapPct,
                ),
            ]);
        }
    }

    private function normalizePayload(array $data): array
    {
        if (array_key_exists('symbol', $data)) {
            $data['symbol'] = strtoupper(trim((string) $data['symbol']));
        }
        if (array_key_exists('is_active', $data)) {
            $data['enabled'] = (bool) $data['is_active'];
            unset($data['is_active']);
        }
        if (! array_key_exists('algorithm', $data) && (($data['trigger_mode'] ?? null) === 'trailing_ratio')) {
            $data['algorithm'] = 'trailing_ratio';
        }
        if (($data['market_type'] ?? null) === 'futures' && ! array_key_exists('side_mode', $data)) {
            $data['side_mode'] = 'both';
        }

        // Most ratio_bot_configs columns are NOT NULL with a DB-level default
        // (e.g. max_position_pct defaults to 1.00). Validation marks nearly
        // every field "nullable" so partial payloads are accepted, but an
        // explicit null in the insert bypasses the column default entirely
        // and trips a "column cannot be null" SQL error. Omitting the key
        // lets the DB default apply instead — only strip null for columns
        // that are genuinely nullable in the schema.
        foreach ($data as $key => $value) {
            if ($value === null && ! in_array($key, self::NULLABLE_COLUMNS, true)) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Columns on ratio_bot_configs that are actually ->nullable() in the
     * migrations — everything else has a NOT NULL + default definition, so
     * an explicit null must be stripped rather than inserted (see
     * normalizePayload()).
     */
    private const NULLABLE_COLUMNS = [
        'partial_sell_pct', 'partial_buy_pct',
        'daily_loss_limit_pct', 'max_drawdown_pct',
        'stop_loss_pct', 'take_profit_pct', 'time_stop_minutes',
        'connection_id', 'integration_id',
        'base_asset_budget', 'quote_asset_budget',
        'strategy_params',
        'stale_feed_timeout_seconds',
        'leverage', 'margin_type', 'position_mode',
        'max_open_position_per_symbol',
        'risk_per_trade', 'max_daily_loss', 'max_consecutive_losses',
        'max_allowed_slippage_bps',
        'max_trades_per_hour', 'max_trades_per_day',
        'selling_loss_ratio', 'buying_loss_ratio',
        'max_spike_deviation_bps',
        'max_entry_price_drift_bps',
        'recovery_slot_allocation_pct',
        'recovery_slot_target_pct',
        'recovery_timeout_minutes',
        'starting_entry',
        'recovery_buy_drop_pct',
        'recovery_sell_rise_pct',
        'no_trade_alert_minutes',
        'liquidation_watch_buffer_pct',
        'liquidation_critical_buffer_pct',
        'futures_recovery_topup_pct',
        'futures_recovery_timeout_minutes',
        'futures_recovery_min_confidence',
        'recovery_lot_min_profit_ratio',
        'forecast_horizon_seconds',
    ];
}
