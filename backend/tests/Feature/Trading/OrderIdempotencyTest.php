<?php

use App\Domain\Execution\ExecutionEngine;
use App\Models\Ledger;
use App\Models\Order;
use App\Models\Symbol;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Carbon\CarbonImmutable;

uses(RefreshDatabase::class);

test('executePaper is idempotent', function () {
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'name' => 'WS',
    ]);
    $strategy = \App\Models\Strategy::create([
        'id' => (string) Str::uuid(),
        'name' => 'MA',
        'slug' => 'ma_rsi',
    ]);
    $instance = \App\Models\StrategyInstance::create([
        'id' => 'inst',
        'workspace_id' => $workspace->id,
        'strategy_id' => $strategy->id,
        'name' => 'Inst',
        'mode' => 'paper',
        'status' => 'running',
    ]);
    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
    ]);
    Ledger::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'balance' => 10000,
    ]);

    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-01-06 11:00')));
    $engine = app(ExecutionEngine::class);
    $key = 'abc-123';
    $engine->executePaper($workspace->id, $instance->id, $symbol->symbol, 'buy', 0.01, 100, $key);
    $engine->executePaper($workspace->id, $instance->id, $symbol->symbol, 'buy', 0.01, 100, $key);

    expect(Order::where('idempotency_key', $key)->count())->toBe(1);
    $ledger = Ledger::first();
    expect($ledger->balance)->toBeLessThan(10000);
});
