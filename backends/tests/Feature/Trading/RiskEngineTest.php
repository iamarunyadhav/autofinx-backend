<?php

use App\Domain\Risk\RiskEngine;
use App\Domain\Market\MarketDataService;
use App\Domain\Ops\KillSwitchService;
use App\Models\EquitySnapshot;
use App\Models\InstanceRiskProfile;
use App\Models\Ledger;
use App\Models\Position;
use App\Models\RiskProfile;
use App\Models\Strategy;
use App\Models\StrategyInstance;
use App\Models\StrategyParam;
use App\Models\Symbol;
use App\Models\Workspace;
use App\Support\Clock\FrozenClock;
use App\Support\Clock\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;

uses(RefreshDatabase::class);

function makeWorkspaceWithBasics(): array
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'WS',
    ]);
    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
        'type' => 'crypto',
    ]);
    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 10000,
    ]);
    return [$workspace, $symbol];
}

test('max trade size rejects when exceeding limit', function () {
    [$workspace, $symbol] = makeWorkspaceWithBasics();
    $strategy = Strategy::create([
        'id' => (string) Str::uuid(),
        'name' => 'MA',
        'slug' => 'ma_rsi',
    ]);
    $instance = StrategyInstance::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'strategy_id' => $strategy->id,
        'name' => 'inst',
        'mode' => 'paper',
        'status' => 'running',
    ]);

    $riskProfile = RiskProfile::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'tight',
        'rules' => ['max_trade_pct' => 1],
    ]);
    InstanceRiskProfile::create([
        'id' => (string) Str::uuid(),
        'instance_id' => $instance->id,
        'risk_profile_id' => $riskProfile->id,
    ]);

    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-01-06 10:00')));
    $engine = app(RiskEngine::class);
    $decision = $engine->assess($instance, $symbol->symbol, 'buy', 50000, 1);
    expect($decision->status)->toBe('REJECT')->and($decision->limitsHit)->toContain('max_trade_pct');
});

test('drawdown triggers kill switch', function () {
    [$workspace, $symbol] = makeWorkspaceWithBasics();
    $strategy = Strategy::create([
        'id' => (string) Str::uuid(),
        'name' => 'MA',
        'slug' => 'ma_rsi',
    ]);
    $instance = StrategyInstance::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'strategy_id' => $strategy->id,
        'name' => 'inst',
        'mode' => 'paper',
        'status' => 'running',
    ]);

    EquitySnapshot::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'equity' => 20000,
        'recorded_at' => CarbonImmutable::parse('2026-01-05 10:00'),
    ]);

    $engine = app(RiskEngine::class);
    $decision = $engine->assess($instance, $symbol->symbol, 'sell', 10000, 0.1);
    expect($decision->status)->toBe('REJECT')->and($decision->limitsHit)->toContain('drawdown');
});

test('volatility breaker blocks when stddev too high', function () {
    [$workspace, $symbol] = makeWorkspaceWithBasics();
    $strategy = Strategy::create([
        'id' => (string) Str::uuid(),
        'name' => 'MA',
        'slug' => 'ma_rsi',
    ]);
    $instance = StrategyInstance::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'strategy_id' => $strategy->id,
        'name' => 'inst',
        'mode' => 'paper',
        'status' => 'running',
    ]);

    // create volatile candles
    for ($i = 0; $i < 20; $i++) {
        \App\Models\MarketCandle::create([
            'id' => (string) Str::uuid(),
            'symbol_id' => $symbol->id,
            'timeframe' => '1m',
            'opens_at' => CarbonImmutable::parse("2026-01-06 10:{$i}:00"),
            'open' => 100 + ($i % 2 ? 10 : -10),
            'high' => 110,
            'low' => 90,
            'close' => 100 + ($i % 2 ? 10 : -10),
            'volume' => 1,
        ]);
    }

    $engine = app(RiskEngine::class);
    $decision = $engine->assess($instance, $symbol->symbol, 'buy', 100, 0.01);
    expect($decision->status)->toBe('REJECT')->and($decision->limitsHit)->toContain('volatility');
});
