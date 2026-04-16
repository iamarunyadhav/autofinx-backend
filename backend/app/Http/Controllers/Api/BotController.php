<?php

namespace App\Http\Controllers\Api;

use App\Domain\Assets\AssetRegistryService;
use App\Domain\Intelligence\MasterBrainService;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Market\MetalsMarketDataService;
use App\Domain\Ops\KillSwitchService;
use App\Domain\Ops\TradingEnvironmentGuard;
use App\Domain\Strategies\StrategyParamSchemaService;
use App\Domain\Trading\BotLogService;
use App\Domain\Trading\TradingModuleSettingsService;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Http\Controllers\Controller;
use App\Jobs\EvaluateBotJob;
use App\Jobs\ExecuteSignalJob;
use App\Models\AiRecommendation;
use App\Models\Bot;
use App\Models\Order;
use App\Models\Position;
use App\Models\Trade;
use App\Models\TradeSignal;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BotController extends Controller
{
    public function index(Request $request, string $workspaceId)
    {
        $query = Bot::with('riskProfile')
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', strtoupper((string) $request->query('status')));
        }
        if ($request->filled('asset_class')) {
            $query->where('asset_class', strtoupper((string) $request->query('asset_class')));
        }
        if ($request->filled('metal_type')) {
            $query->where('metal_type', strtoupper((string) $request->query('metal_type')));
        }

        return response()->json($query->get());
    }

    public function store(
        Request $request,
        string $workspaceId,
        AssetRegistryService $assets,
        StrategyParamSchemaService $schemas,
        TradingModuleSettingsService $settings
    )
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'connection_id' => 'nullable|uuid|exists:exchange_connections,id',
            'exchange' => 'nullable|string|max:24',
            'symbol' => 'nullable|string|max:32',
            'asset_class' => 'nullable|string|in:CRYPTO,METALS,crypto,metals',
            'metal_type' => 'nullable|string|in:GOLD,SILVER,BRONZE,gold,silver,bronze',
            'asset_code' => 'nullable|string|max:32',
            'quote_currency' => 'nullable|string|in:USD,LKR,USDT,usd,lkr,usdt',
            'provider' => 'nullable|string|max:32',
            'interval_sec' => 'nullable|integer|min:1|max:3600',
            'is_live' => 'boolean',
            'risk_profile_id' => 'nullable|uuid|exists:risk_profiles,id',
            'strategy_type' => 'nullable|string|max:40',
            'strategy_params' => 'nullable|array',
            'allocation_mode' => 'nullable|string|in:FIXED,PERCENT_WALLET,STRATEGY,fixed,percent_wallet,strategy',
            'allocation_percent' => 'nullable|numeric|min:0|max:100',
            'trade_allocation_amount' => 'nullable|numeric|min:0',
            'trade_allocation_currency' => 'nullable|string|max:10',
        ]);

        $assetClass = strtoupper((string) ($data['asset_class'] ?? 'CRYPTO'));
        $metalType = strtoupper((string) ($data['metal_type'] ?? ''));
        $assetCode = strtoupper((string) ($data['asset_code'] ?? $data['symbol'] ?? ''));
        if ($assetClass === 'METALS' && $metalType !== '') {
            $entry = $assets->find('METALS', $metalType);
            $assetCode = strtoupper((string) ($entry['asset_code'] ?? $assetCode));
        }
        if ($assetCode === '') {
            return response()->json(['message' => 'asset_code or symbol is required.'], 422);
        }

        $provider = strtolower((string) ($data['provider'] ?? ($assetClass === 'METALS' ? 'metal_broker' : 'binance')));
        $quoteCurrency = strtoupper((string) ($data['quote_currency'] ?? ($assetClass === 'METALS' ? 'USD' : 'USDT')));
        $strategyType = strtolower((string) ($data['strategy_type'] ?? 'threshold'));
        $defaultInterval = (int) ($settings->tradingState($workspaceId)['bot_default_interval_seconds'] ?? 3600);
        if (! $schemas->isSupported($strategyType)) {
            return response()->json(['message' => "Unsupported strategy_type [{$strategyType}]"], 422);
        }
        $strategyParams = $schemas->normalize($strategyType, (array) ($data['strategy_params'] ?? []), $assetClass);

        $bot = Bot::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'user_id' => $request->user()->id ?? null,
            'connection_id' => $data['connection_id'] ?? null,
            'name' => $data['name'],
            'asset_class' => $assetClass,
            'metal_type' => $metalType !== '' ? $metalType : null,
            'asset_code' => $assetCode,
            'quote_currency' => $quoteCurrency,
            'provider' => $provider,
            'exchange' => strtolower((string) ($data['exchange'] ?? $provider)),
            'symbol' => $assetCode,
            'interval_sec' => (int) ($data['interval_sec'] ?? $defaultInterval),
            'is_live' => (bool) ($data['is_live'] ?? false),
            'status' => 'STOPPED',
            'risk_profile_id' => $data['risk_profile_id'] ?? null,
            'strategy_type' => $strategyType,
            'strategy_params' => $strategyParams,
            'allocation_mode' => strtoupper((string) ($data['allocation_mode'] ?? 'FIXED')),
            'allocation_percent' => isset($data['allocation_percent']) ? (float) $data['allocation_percent'] : null,
            'trade_allocation_amount' => isset($data['trade_allocation_amount']) ? (float) $data['trade_allocation_amount'] : null,
            'trade_allocation_currency' => isset($data['trade_allocation_currency']) ? strtoupper((string) $data['trade_allocation_currency']) : $quoteCurrency,
        ]);

        return response()->json($bot, 201);
    }

    public function update(Request $request, Bot $bot, StrategyParamSchemaService $schemas)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'connection_id' => 'nullable|uuid|exists:exchange_connections,id',
            'symbol' => 'sometimes|string|max:32',
            'asset_class' => 'sometimes|string|in:CRYPTO,METALS,crypto,metals',
            'metal_type' => 'nullable|string|in:GOLD,SILVER,BRONZE,gold,silver,bronze',
            'asset_code' => 'sometimes|string|max:32',
            'quote_currency' => 'sometimes|string|in:USD,LKR,USDT,usd,lkr,usdt',
            'provider' => 'sometimes|string|max:32',
            'exchange' => 'sometimes|string|max:24',
            'interval_sec' => 'sometimes|integer|min:1|max:3600',
            'is_live' => 'sometimes|boolean',
            'risk_profile_id' => 'nullable|uuid|exists:risk_profiles,id',
            'strategy_type' => 'sometimes|string|max:40',
            'strategy_params' => 'sometimes|array',
            'allocation_mode' => 'sometimes|string|in:FIXED,PERCENT_WALLET,STRATEGY,fixed,percent_wallet,strategy',
            'allocation_percent' => 'nullable|numeric|min:0|max:100',
            'trade_allocation_amount' => 'nullable|numeric|min:0',
            'trade_allocation_currency' => 'nullable|string|max:10',
            'status' => 'sometimes|string|in:RUNNING,PAUSED,STOPPED',
        ]);

        if (isset($data['symbol'])) {
            $data['symbol'] = strtoupper((string) $data['symbol']);
        }
        if (isset($data['asset_code'])) {
            $data['asset_code'] = strtoupper((string) $data['asset_code']);
            $data['symbol'] = $data['asset_code'];
        }
        if (isset($data['asset_class'])) {
            $data['asset_class'] = strtoupper((string) $data['asset_class']);
        }
        if (array_key_exists('metal_type', $data)) {
            $data['metal_type'] = $data['metal_type'] ? strtoupper((string) $data['metal_type']) : null;
        }
        if (isset($data['quote_currency'])) {
            $data['quote_currency'] = strtoupper((string) $data['quote_currency']);
        }
        if (isset($data['provider'])) {
            $data['provider'] = strtolower((string) $data['provider']);
        }
        if (isset($data['strategy_type'])) {
            $data['strategy_type'] = strtolower((string) $data['strategy_type']);
        }
        if (isset($data['exchange'])) {
            $data['exchange'] = strtolower((string) $data['exchange']);
        }
        if (isset($data['status'])) {
            $data['status'] = strtoupper((string) $data['status']);
        }
        if (isset($data['allocation_mode'])) {
            $data['allocation_mode'] = strtoupper((string) $data['allocation_mode']);
        }
        if (isset($data['trade_allocation_currency'])) {
            $data['trade_allocation_currency'] = strtoupper((string) $data['trade_allocation_currency']);
        }

        if (array_key_exists('strategy_type', $data) || array_key_exists('strategy_params', $data)) {
            $strategyType = strtolower((string) ($data['strategy_type'] ?? $bot->strategy_type ?? 'threshold'));
            if (! $schemas->isSupported($strategyType)) {
                return response()->json(['message' => "Unsupported strategy_type [{$strategyType}]"], 422);
            }

            $assetClass = strtoupper((string) ($data['asset_class'] ?? $bot->asset_class ?? 'CRYPTO'));
            $paramsInput = array_key_exists('strategy_params', $data)
                ? (array) $data['strategy_params']
                : (array) ($bot->strategy_params ?? []);

            $data['strategy_type'] = $strategyType;
            $data['strategy_params'] = $schemas->normalize($strategyType, $paramsInput, $assetClass);
        }

        $bot->update($data);

        return response()->json($bot->fresh());
    }

    public function destroy(Bot $bot)
    {
        $bot->delete();
        return response()->json(['message' => 'Bot deleted']);
    }

    public function start(
        Bot $bot,
        BotLogService $logs,
        TradingEnvironmentGuard $modeGuard,
        KillSwitchService $killSwitch,
        TradingModuleSettingsService $settings
    )
    {
        if (! $settings->automationEnabled((string) $bot->workspace_id)) {
            return response()->json(['message' => 'Auto trading is disabled for this workspace. Enable it in Settings before starting bots.'], 422);
        }

        try {
            $modeGuard->assertBotMode($bot);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($bot->is_live && $killSwitch->isActive((string) $bot->workspace_id)) {
            return response()->json(['message' => 'Kill switch is active for this workspace.'], 422);
        }

        $provider = strtolower((string) ($bot->provider ?: ($bot->isMetals() ? 'metal_broker' : 'binance')));
        if ($bot->is_live && $provider === 'binance' && (! $bot->connection_id || ! $bot->connection || ! $bot->connection->is_active)) {
            return response()->json(['message' => 'Live Binance bot requires an active exchange connection.'], 422);
        }
        if ($bot->is_live && $provider === 'metal_broker' && (! $bot->connection || ! $bot->connection->is_active) && (string) config('services.metal_broker.api_key', '') === '') {
            return response()->json(['message' => 'Live metals bot requires an active provider connection or METAL_BROKER_API_KEY.'], 422);
        }

        if ($bot->cooldown_until && now()->lt($bot->cooldown_until)) {
            return response()->json([
                'message' => 'Bot is in cooldown after risk stop.',
                'cooldown_until' => $bot->cooldown_until,
            ], 422);
        }

        $bot->status = 'RUNNING';
        $bot->last_started_at = now();
        $bot->last_error = null;
        $bot->save();

        $traceId = (string) Str::uuid();
        $logs->log($bot->id, $bot->workspace_id, 'INFO', 'Bot started from UI.', [], $traceId);
        EvaluateBotJob::dispatch($bot->id);

        return response()->json($bot->fresh());
    }

    public function pause(Bot $bot, BotLogService $logs)
    {
        $bot->status = 'PAUSED';
        $bot->save();
        $logs->log($bot->id, $bot->workspace_id, 'WARN', 'Bot paused from UI.', [], (string) Str::uuid());

        return response()->json($bot->fresh());
    }

    public function stop(Bot $bot, BotLogService $logs)
    {
        $bot->status = 'STOPPED';
        $bot->save();
        $logs->log($bot->id, $bot->workspace_id, 'WARN', 'Bot stopped from UI.', [], (string) Str::uuid());

        return response()->json($bot->fresh());
    }

    public function emergencyStop(Bot $bot, TradingVenueClientFactory $venues, BotLogService $logs)
    {
        $traceId = (string) Str::uuid();

        $openOrders = Order::where('bot_id', $bot->id)
            ->whereIn('status', ['NEW', 'PARTIAL'])
            ->whereNotNull('exchange_order_id')
            ->get();

        foreach ($openOrders as $order) {
            try {
                if ($order->exchange_order_id) {
                    $client = $venues->forBot($bot);
                    $assetCode = strtoupper((string) ($order->asset_code ?: $order->symbol?->symbol ?: $bot->asset_code ?: $bot->symbol));
                    $client->cancelOrder((string) $order->exchange_order_id, $assetCode);
                }
                $order->status = 'CANCELLED';
                $order->save();
            } catch (\Throwable $e) {
                $logs->log($bot->id, $bot->workspace_id, 'WARN', 'Emergency cancel failed for order.', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ], $traceId);
            }
        }

        $bot->status = 'PAUSED';
        $bot->save();
        $logs->log($bot->id, $bot->workspace_id, 'ERROR', 'Emergency stop executed.', [
            'cancelled_orders' => $openOrders->count(),
        ], $traceId);

        return response()->json([
            'bot' => $bot->fresh(),
            'cancelled_orders' => $openOrders->count(),
        ]);
    }

    public function closePositionNow(
        Bot $bot,
        Request $request,
        MarketPriceCacheService $prices,
        MetalsMarketDataService $metals,
    )
    {
        $request->validate([
            'max_slippage_pct' => 'nullable|numeric|min:0|max:10',
            'ttl_ms' => 'nullable|integer|min:100|max:60000',
            'fill_timeout_ms' => 'nullable|integer|min:100|max:60000',
        ]);

        $position = Position::where('bot_id', $bot->id)
            ->whereRaw('COALESCE(qty, quantity, 0) > 0')
            ->orderByDesc('updated_at')
            ->first();
        if (! $position) {
            return response()->json(['message' => 'No open position to close.'], 422);
        }

        $assetClass = strtoupper((string) ($bot->asset_class ?? 'CRYPTO'));
        $assetCode = strtoupper((string) ($bot->asset_code ?: $bot->symbol));
        $provider = strtolower((string) ($bot->provider ?: ($assetClass === 'METALS' ? 'metal_broker' : $bot->exchange)));
        $quoteCurrency = strtoupper((string) ($bot->quote_currency ?: 'USD'));

        if ($assetClass === 'METALS') {
            $quote = $metals->getPrice($assetCode, $quoteCurrency, $provider);
            $decisionPrice = $quoteCurrency === 'LKR'
                ? (float) ($quote['lkr']['mid'] ?? 0)
                : (float) ($quote['usd']['mid'] ?? 0);
        } else {
            $priceRow = $prices->getLatestPrice($provider, $assetCode);
            $decisionPrice = (float) ($priceRow['price'] ?? $position->last_mark_price ?? $position->avg_entry ?? 0);
        }

        if ($decisionPrice <= 0) {
            return response()->json(['message' => 'Unable to fetch current price for close action.'], 422);
        }

        $traceId = (string) Str::uuid();
        $signal = TradeSignal::create([
            'id' => (string) Str::uuid(),
            'bot_id' => $bot->id,
            'workspace_id' => $bot->workspace_id,
            'user_id' => $bot->user_id,
            'asset_class' => $assetClass,
            'asset_code' => $assetCode,
            'quote_currency' => $quoteCurrency,
            'provider' => $provider,
            'symbol' => $assetCode,
            'side' => 'SELL',
            'decision_price' => $decisionPrice,
            'decision_ts' => now(),
            'max_slippage_pct' => (float) ($request->input('max_slippage_pct') ?? $bot->riskProfile?->max_slippage_pct_default ?? 0.5),
            'ttl_ms' => (int) ($request->input('ttl_ms') ?? 8000),
            'fill_timeout_ms' => (int) ($request->input('fill_timeout_ms') ?? 3000),
            'exec_policy' => 'SAFE',
            'status' => 'PENDING',
            'trace_id' => $traceId,
            'meta_json' => [
                'qty' => (float) ($position->qty ?? $position->quantity ?? 0),
                'reason' => 'close_position_now',
            ],
        ]);

        ExecuteSignalJob::dispatch($signal->id);

        return response()->json([
            'message' => 'Close position signal queued.',
            'signal' => $signal,
        ]);
    }

    public function monitor(Bot $bot, MarketPriceCacheService $prices, MetalsMarketDataService $metals)
    {
        $assetClass = strtoupper((string) ($bot->asset_class ?? 'CRYPTO'));
        $assetCode = strtoupper((string) ($bot->asset_code ?: $bot->symbol));
        $provider = strtolower((string) ($bot->provider ?: ($assetClass === 'METALS' ? 'metal_broker' : $bot->exchange)));
        $quoteCurrency = strtoupper((string) ($bot->quote_currency ?: 'USD'));

        $price = $assetClass === 'METALS'
            ? $metals->getPrice($assetCode, $quoteCurrency, $provider)
            : $prices->getLatestPrice($provider, $assetCode);

        return response()->json([
            'bot' => $bot,
            'price' => $price,
            'signals' => TradeSignal::where('bot_id', $bot->id)->latest('decision_ts')->limit(50)->get(),
            'orders' => Order::where('bot_id', $bot->id)->latest('placed_ts')->limit(50)->get(),
            'trades' => Trade::where('bot_id', $bot->id)->latest('ts')->limit(50)->get(),
            'logs' => $bot->logs()->latest('ts')->limit(50)->get(),
        ]);
    }

    public function logs(Request $request, Bot $bot)
    {
        $query = $bot->logs()->latest('ts');
        if ($request->filled('trace_id')) {
            $query->where('trace_id', (string) $request->query('trace_id'));
        }

        return response()->json($query->limit(200)->get());
    }

    public function aiRecommendations(Request $request, Bot $bot, MasterBrainService $brain)
    {
        $data = $request->validate([
            'limit' => 'nullable|integer|min:1|max:100',
            'refresh' => 'nullable|boolean',
        ]);

        if ((bool) ($data['refresh'] ?? false)) {
            $brain->recommend($bot, false);
        }

        return response()->json([
            'mode' => $brain->mode(),
            'recommendations' => $brain->latestForBot($bot->id, (int) ($data['limit'] ?? 20)),
        ]);
    }

    public function applyAiRecommendation(Request $request, Bot $bot, MasterBrainService $brain)
    {
        $data = $request->validate([
            'recommendation_id' => 'nullable|uuid|exists:ai_recommendations,id',
        ]);

        $recommendation = null;
        if (! empty($data['recommendation_id'])) {
            $recommendation = AiRecommendation::where('id', $data['recommendation_id'])
                ->where('bot_id', $bot->id)
                ->first();
        }

        if (! $recommendation) {
            $recommendation = $brain->recommend($bot, false);
        }

        $applied = $brain->apply($bot, $recommendation);

        return response()->json([
            'mode' => $brain->mode(),
            'recommendation' => $applied,
            'bot' => $bot->fresh(),
        ]);
    }
}
