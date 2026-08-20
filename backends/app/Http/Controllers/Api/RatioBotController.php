<?php

namespace App\Http\Controllers\Api;

use App\Domain\RatioBot\RatioBotDecisionService;
use App\Domain\RatioBot\RatioBotRuntimeQueryService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Http\Controllers\Controller;
use App\Jobs\BackfillMarketOhlcJob;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RatioBotController extends Controller
{
    public function __construct(
        private readonly RatioBotStateService    $stateService,
        private readonly RatioBotDecisionService $decisionService,
        private readonly RatioBotRuntimeQueryService $runtime,
    ) {
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
        $data = $this->validatedPayload($request);

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
        $data = $this->validatedPayload($request, true);

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
        $ratioBot->delete();
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
        $state = $this->stateService->start($ratioBot, $request->user()?->id);
        return response()->json(['state' => $state, 'message' => 'Bot started. Set initial anchor via /anchor-buy if not already set.']);
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
            'cooldown_until' => optional($ratioBot->state?->cooldown_until)->toIso8601String(),
            'kill_switch' => (bool) $ratioBot->kill_switch,
            'error_state' => $ratioBot->state?->last_runtime_error,
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
        RunRatioBotEvaluationJob::dispatch($ratioBot->id, false);
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

    private function validatedPayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $optional = $partial ? 'sometimes' : 'nullable';

        return $request->validate([
            'name'                         => $optional . '|string|max:120',
            'symbol'                       => "{$required}|string|max:32",
            'exchange'                     => 'nullable|string|max:32',
            'market_type'                  => $optional . '|string|in:spot,futures',
            'algorithm'                    => $optional . '|string|in:ratio_threshold,trailing_ratio,ma_crossover,ma,rsi,breakout',
            'timeframe'                    => $optional . '|string|in:1m,3m,5m,15m,1h,4h',
            'brain_enabled'                => 'nullable|boolean',
            'brain_min_confidence'         => 'nullable|integer|min:0|max:100',
            'side_mode'                    => $optional . '|string|in:buy_sell,long_only,short_only,both',
            'mode'                         => $optional . '|string|in:advisor,manual_approval,restricted_auto',
            'execution_mode'               => $optional . '|string|in:sandbox,live',
            'base_quote_amount'            => $optional . '|numeric|min:0',
            'initial_entry_pct'            => 'nullable|numeric|min:0.01|max:1',
            'reserve_tranche_count'        => 'nullable|integer|min:0|max:10',
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
            'recovery_timeout_minutes'     => 'nullable|integer|min:1|max:100000',
        ]);
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

        return $data;
    }
}
