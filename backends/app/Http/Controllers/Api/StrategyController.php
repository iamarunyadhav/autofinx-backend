<?php

namespace App\Http\Controllers\Api;

use App\Domain\Backtest\BacktestEngine;
use App\Domain\Market\MarketDataService;
use App\Domain\Strategies\StrategyRegistry;
use App\Http\Controllers\Controller;
use App\Models\Strategy;
use App\Models\StrategyInstance;
use App\Models\StrategyParam;
use App\Models\InstanceRiskProfile;
use App\Support\ConfigVersioning;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StrategyController extends Controller
{
    public function index(StrategyRegistry $registry)
    {
        $this->ensureBuiltInStrategies($registry);
        return response()->json(Strategy::all());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'slug' => 'required|string|unique:strategies,slug',
            'description' => 'nullable|string',
            'params_schema' => 'nullable|array',
            'category' => 'nullable|string',
        ]);

        $strategy = Strategy::create($data + ['enabled' => true]);

        return response()->json($strategy, 201);
    }

    public function show(Strategy $strategy)
    {
        return response()->json($strategy);
    }

    public function update(Request $request, Strategy $strategy)
    {
        $data = $request->validate([
            'name' => 'sometimes|string',
            'description' => 'nullable|string',
            'params_schema' => 'nullable|array',
            'enabled' => 'boolean',
            'category' => 'nullable|string',
        ]);

        $previous = $strategy->toArray();
        $strategy->update($data);
        if ($strategy->category) {
            ConfigVersioning::record(
                $request->input('workspace_id') ?? '',
                'strategy.'.$strategy->id,
                $strategy->toArray(),
                $previous,
                $request->user()->id ?? null
            );
        }

        return response()->json($strategy);
    }

    public function destroy(Strategy $strategy)
    {
        $strategy->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function instances(Request $request, string $workspaceId)
    {
        $instances = StrategyInstance::with(['strategy', 'params', 'connection'])
            ->where('workspace_id', $workspaceId)->get();

        return response()->json($instances);
    }

    public function createInstance(Request $request, string $workspaceId)
    {
        $data = $request->validate([
            'strategy_id' => 'required|exists:strategies,id',
            'name' => 'required|string',
            'mode' => 'required|string',
            'status' => 'nullable|string',
            'params' => 'nullable|array',
            'connection_id' => 'nullable|uuid|exists:exchange_connections,id',
        ]);

        if (strtolower((string) $data['mode']) === 'live') {
            return response()->json([
                'message' => 'Strategy-instance live mode is disabled during Phase 1 execution hardening. Use paper mode or live bots/manual live orders only.',
            ], 422);
        }

        $instance = StrategyInstance::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'strategy_id' => $data['strategy_id'],
            'name' => $data['name'],
            'mode' => $data['mode'],
            'status' => $data['status'] ?? 'idle',
            'connection_id' => $data['connection_id'] ?? null,
        ]);

        foreach ($data['params'] ?? [] as $key => $value) {
            StrategyParam::create([
                'id' => (string) Str::uuid(),
                'instance_id' => $instance->id,
                'key' => $key,
                'value' => $value,
            ]);
        }

        ConfigVersioning::record(
            $workspaceId,
            'strategy_instance.'.$instance->id,
            $instance->load('params')->toArray(),
            null,
            $request->user()->id ?? null
        );

        return response()->json($instance->load('params'), 201);
    }

    public function updateInstance(Request $request, StrategyInstance $instance)
    {
        $data = $request->validate([
            'name' => 'sometimes|string',
            'mode' => 'sometimes|string',
            'status' => 'sometimes|string',
            'params' => 'nullable|array',
            'risk_profile_id' => 'nullable|uuid',
            'connection_id' => 'nullable|uuid|exists:exchange_connections,id',
        ]);

        if (isset($data['mode']) && strtolower((string) $data['mode']) === 'live') {
            return response()->json([
                'message' => 'Strategy-instance live mode is disabled during Phase 1 execution hardening.',
            ], 422);
        }

        $previous = $instance->load('params')->toArray();
        $instance->update($data);

        if (isset($data['params'])) {
            foreach ($data['params'] as $key => $value) {
                StrategyParam::updateOrCreate(
                    ['instance_id' => $instance->id, 'key' => $key],
                    ['value' => $value]
                );
            }
        }

        if (!empty($data['risk_profile_id'])) {
            InstanceRiskProfile::updateOrCreate(
                ['instance_id' => $instance->id],
                ['risk_profile_id' => $data['risk_profile_id']]
            );
        }

        $updated = $instance->load('params');

        ConfigVersioning::record(
            $instance->workspace_id,
            'strategy_instance.'.$instance->id,
            $updated->toArray(),
            $previous,
            $request->user()->id ?? null
        );

        return response()->json($updated->load('connection'));
    }

    public function deleteInstance(StrategyInstance $instance)
    {
        $instance->delete();
        return response()->json(['message' => 'Instance deleted']);
    }

    public function performance(StrategyInstance $instance)
    {
        $snapshots = \App\Models\StrategyPerformanceSnapshot::where('strategy_instance_id', $instance->id)
            ->orderByDesc('captured_at')
            ->limit(50)
            ->get();
        return $snapshots;
    }

    public function backtest(Request $request, StrategyInstance $instance, StrategyRegistry $registry, MarketDataService $market, BacktestEngine $engine)
    {
        $data = $request->validate([
            'symbol' => 'nullable|string|max:32',
            'candle_timeframe' => 'nullable|string|max:16',
            'limit' => 'nullable|integer|min:50|max:5000',
            'initial_capital' => 'nullable|numeric|min:100|max:100000000',
            'position_size_pct' => 'nullable|numeric|min:1|max:100',
            'fee_bps' => 'nullable|numeric|min:0|max:500',
            'slippage_bps' => 'nullable|numeric|min:0|max:500',
            'stop_loss_pct' => 'nullable|numeric|min:0.01|max:100',
            'take_profit_pct' => 'nullable|numeric|min:0.01|max:100',
            'time_stop_candles' => 'nullable|integer|min:1|max:5000',
        ]);

        $strategy = $registry->resolve($instance->strategy);
        if (! $strategy) {
            return response()->json([
                'message' => 'Strategy implementation is not available for backtesting.',
            ], 422);
        }

        $params = $this->instanceParams($instance);
        $symbol = $this->normalizeSymbol((string) ($data['symbol'] ?? $params['symbol'] ?? 'BTCUSDT'));
        $candleTimeframe = (string) ($data['candle_timeframe'] ?? '1h');
        $limit = (int) ($data['limit'] ?? 720);
        $candles = $market->candles($symbol, $candleTimeframe, $limit);

        if (count($candles) < 25) {
            return response()->json([
                'message' => "Not enough stored market candles for {$symbol} {$candleTimeframe}. Backtests now require real candle history and will not use synthetic data.",
            ], 422);
        }

        $result = $engine->run($strategy, $candles, $params, [
            'initial_capital' => $data['initial_capital'] ?? 10000,
            'position_size_pct' => $data['position_size_pct'] ?? 20,
            'fee_bps' => $data['fee_bps'] ?? 10,
            'slippage_bps' => $data['slippage_bps'] ?? 5,
            'stop_loss_pct' => $data['stop_loss_pct'] ?? $params['stop_loss_pct'] ?? null,
            'take_profit_pct' => $data['take_profit_pct'] ?? $params['take_profit_pct'] ?? null,
            'time_stop_candles' => $data['time_stop_candles'] ?? $params['time_stop_candles'] ?? null,
        ]);

        return response()->json([
            ...$result,
            'meta' => array_merge($result['meta'] ?? [], [
                'symbol' => $symbol,
                'candle_timeframe' => $candleTimeframe,
                'instance_id' => $instance->id,
                'instance_name' => $instance->name,
            ]),
        ]);
    }

    private function ensureBuiltInStrategies(StrategyRegistry $registry): void
    {
        foreach ($registry->all() as $slug => $implementation) {
            Strategy::firstOrCreate(
                ['slug' => $slug],
                [
                    'id' => (string) Str::uuid(),
                    'slug' => $slug,
                    'name' => $implementation->name(),
                    'params_schema' => $implementation->paramsSchema(),
                    'enabled' => true,
                ]
            );
        }
    }

    private function instanceParams(StrategyInstance $instance): array
    {
        return StrategyParam::query()
            ->where('instance_id', $instance->id)
            ->get()
            ->mapWithKeys(function (StrategyParam $param) {
                return [$param->key => $this->normalizeParamValue($param->value)];
            })
            ->toArray();
    }

    private function normalizeParamValue(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('value', $value) && count($value) === 1) {
            return $value['value'];
        }

        return $value;
    }

    private function normalizeSymbol(string $symbol): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', trim($symbol)));
    }
}
