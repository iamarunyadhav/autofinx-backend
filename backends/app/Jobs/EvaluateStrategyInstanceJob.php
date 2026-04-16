<?php

namespace App\Jobs;

use App\Domain\Scheduler\ScheduleGate;
use App\Domain\Strategies\StrategyInterface;
use App\Domain\Strategies\StrategyRegistry;
use App\Models\AuditLog;
use App\Domain\Market\MarketDataService;
use App\Domain\Risk\RiskEngine;
use App\Domain\Execution\ExecutionEngine;
use App\Domain\Portfolio\PreTradeSizer;
use App\Domain\Regime\RegimeDetectorService;
use App\Domain\Regime\StrategyAutoSwitchService;
use App\Domain\Signals\SignalPublisherService;
use App\Models\StrategyInstance;
use App\Models\StrategyParam;
use App\Models\Ledger;
use App\Support\Clock\Clock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class EvaluateStrategyInstanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $strategyInstanceId)
    {
        $this->onQueue('strategies');
    }

    public function handle(ScheduleGate $gate, Clock $clock, PreTradeSizer $sizer, RegimeDetectorService $regimeDetector, SignalPublisherService $publisher, StrategyAutoSwitchService $switcher, ?StrategyRegistry $registry = null): void
    {
        $registry ??= app(StrategyRegistry::class);

        $instance = StrategyInstance::find($this->strategyInstanceId);
        if (! $instance || $instance->status !== 'running') {
            return;
        }

        $now = $clock->now();
        $check = $gate->canEvaluateStrategyInstance($instance, $now);
        if (! $check->allowed) {
            return;
        }

        $strategy = $this->resolveStrategy($instance, $registry);
        if (! $strategy) {
            return;
        }

        $params = StrategyParam::where('instance_id', $instance->id)
            ->get()
            ->pluck('value', 'key')
            ->toArray();
        $symbol = $params['symbol'] ?? 'BTCUSDT';

        $market = app(MarketDataService::class);
        $candles = $market->candles($symbol, '1m', 200);
        $regimeModel = $regimeDetector->detect($symbol, $instance->workspace_id);
        $regime = $regimeModel?->regime;
        if (! $switcher->allowOrSwitch($instance, $regime)) {
            return;
        }
        $signal = $strategy->evaluate($candles, $params);
        if (!in_array($signal->action, ['BUY', 'SELL'])) {
            return;
        }

        $price = end($candles)['close'] ?? $market->latestPrice($symbol) ?? 0;
        if ($price <= 0) {
            return;
        }

        $ledger = Ledger::where('workspace_id', $instance->workspace_id)->first();
        $equity = $ledger?->balance ?? 10000;
        $qty = $sizer->size($instance->workspace_id, $symbol, $equity, $price, (float) env('RISK_DEFAULT_MAX_TRADE_PCT', 2));

        $risk = app(RiskEngine::class);
        $decision = $risk->assess($instance, $symbol, strtolower($signal->action), $price, $qty);
        if ($decision->status !== 'APPROVE') {
            return;
        }

        if ($instance->mode === 'live') {
            $instance->status = 'paused';
            $instance->save();

            AuditLog::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $instance->workspace_id,
                'action' => 'strategy_live_execution_disabled',
                'actor_id' => null,
                'actor_type' => 'system',
                'metadata' => [
                    'instance_id' => $instance->id,
                    'reason' => 'Phase 1 disables legacy strategy-instance live execution until the canonical live router is in place.',
                ],
            ]);

            return;
        }

        $execution = app(ExecutionEngine::class);
        $idempotencyKey = $instance->id . '-' . $now->format('YmdHi') . '-' . $signal->action;

        // publish signal for marketplace/feed
        $publisher->publish($instance->workspace_id, $symbol, $signal->action, $signal->confidence, [
            'regime' => $regime,
            'strategy_name' => $strategy->name(),
        ]);

        $execution->executePaper($instance->workspace_id, $instance->id, $symbol, strtolower($signal->action), $qty, $price, $idempotencyKey);
    }

    private function resolveStrategy(StrategyInstance $instance, StrategyRegistry $registry): ?StrategyInterface
    {
        return $registry->resolve($instance->strategy);
    }
}
