<?php

namespace App\Http\Controllers\Api;

use App\Domain\RatioBot\RatioBotDecisionService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Http\Controllers\Controller;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class RatioBotController extends Controller
{
    public function __construct(
        private readonly RatioBotStateService    $stateService,
        private readonly RatioBotDecisionService $decisionService,
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
        $data = $request->validate([
            'name'                         => 'nullable|string|max:120',
            'symbol'                       => 'required|string|max:32',
            'exchange'                     => 'nullable|string|max:32',
            'market_type'                  => 'nullable|string|in:spot',
            'mode'                         => 'nullable|string|in:advisor,manual_approval,restricted_auto',
            'execution_mode'               => 'nullable|string|in:sandbox,live',
            'base_quote_amount'            => 'nullable|numeric|min:0',
            'order_size_mode'              => 'nullable|string|in:fixed_quote,fixed_base,percent_of_balance',
            'sell_threshold_ratio'         => 'nullable|numeric|min:0.001|max:100',
            'buy_threshold_ratio'          => 'nullable|numeric|min:0.001|max:100',
            'evaluation_mode'              => 'nullable|string|in:polling,websocket,hybrid',
            'poll_interval_seconds'        => 'nullable|integer|min:60|max:86400',
            'cooldown_seconds_after_trade' => 'nullable|integer|min:0|max:86400',
            'daily_loss_limit_pct'         => 'nullable|numeric|min:0|max:1',
            'max_drawdown_pct'             => 'nullable|numeric|min:0|max:1',
            'max_open_orders'              => 'nullable|integer|min:1|max:10',
            'pause_on_order_failure_streak'=> 'nullable|integer|min:1|max:20',
            'require_stop_loss'            => 'nullable|boolean',
            'stop_loss_pct'                => 'nullable|numeric|min:0.001|max:1',
            'require_take_profit'          => 'nullable|boolean',
            'take_profit_pct'              => 'nullable|numeric|min:0.001|max:100',
            'require_time_stop'            => 'nullable|boolean',
            'time_stop_minutes'            => 'nullable|integer|min:1',
            'connection_id'                => 'nullable|uuid',
        ]);

        $config = RatioBotConfig::create(array_merge($data, [
            'workspace_id' => $workspaceId,
            'enabled'      => false,
        ]));

        // Auto-create state row
        $this->stateService->getOrCreate($config);

        return response()->json($config->load('state'), 201);
    }

    /**
     * GET /ratio-bots/{config}
     */
    public function show(RatioBotConfig $ratioBot): JsonResponse
    {
        return response()->json($ratioBot->load('state'));
    }

    /**
     * PUT /ratio-bots/{config}
     */
    public function update(Request $request, RatioBotConfig $ratioBot): JsonResponse
    {
        $data = $request->validate([
            'name'                         => 'sometimes|string|max:120',
            'symbol'                       => 'sometimes|string|max:32',
            'mode'                         => 'sometimes|string|in:advisor,manual_approval,restricted_auto',
            'execution_mode'               => 'sometimes|string|in:sandbox,live',
            'base_quote_amount'            => 'sometimes|numeric|min:0',
            'order_size_mode'              => 'sometimes|string|in:fixed_quote,fixed_base,percent_of_balance',
            'sell_threshold_ratio'         => 'sometimes|numeric|min:0.001|max:100',
            'buy_threshold_ratio'          => 'sometimes|numeric|min:0.001|max:100',
            'evaluation_mode'              => 'sometimes|string|in:polling,websocket,hybrid',
            'poll_interval_seconds'        => 'sometimes|integer|min:60|max:86400',
            'cooldown_seconds_after_trade' => 'sometimes|integer|min:0|max:86400',
            'daily_loss_limit_pct'         => 'nullable|numeric|min:0|max:1',
            'max_drawdown_pct'             => 'nullable|numeric|min:0|max:1',
            'max_open_orders'              => 'sometimes|integer|min:1|max:10',
            'pause_on_order_failure_streak'=> 'sometimes|integer|min:1|max:20',
            'require_stop_loss'            => 'sometimes|boolean',
            'stop_loss_pct'                => 'nullable|numeric|min:0.001|max:1',
            'require_take_profit'          => 'sometimes|boolean',
            'take_profit_pct'              => 'nullable|numeric|min:0.001|max:100',
            'require_time_stop'            => 'sometimes|boolean',
            'time_stop_minutes'            => 'nullable|integer|min:1',
            'connection_id'                => 'nullable|uuid',
        ]);

        $ratioBot->update($data);

        return response()->json($ratioBot->load('state'));
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
                $baseUrl  = config('services.binance.base_url', 'https://api.binance.com');
                $response = Http::get($baseUrl . '/api/v3/ticker/price', ['symbol' => strtoupper($ratioBot->symbol)]);
                $price    = $response->successful() ? (float) $response->json('price') : null;
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
        RunRatioBotEvaluationJob::dispatch($ratioBot->id, true);
        return response()->json(['message' => 'Evaluation job dispatched.', 'config_id' => $ratioBot->id]);
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
}
