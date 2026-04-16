<?php

use App\Domain\Execution\ExecutionEngine;
use App\Models\Ledger;
use App\Models\Order;
use App\Models\Position;
use App\Models\Symbol;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Carbon\CarbonImmutable;

uses(RefreshDatabase::class);

test('paper execution updates ledger and position', function () {
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

    app()->instance(Clock::class, new FrozenClock(CarbonImmutable::parse('2026-01-06 11:05')));
    $engine = app(ExecutionEngine::class);
    $order = $engine->executePaper($workspace->id, $instance->id, $symbol->symbol, 'buy', 0.1, 100, 'ledger-test');

    $ledger = Ledger::first();
    expect($ledger->balance)->toBeLessThan(10000);
    $position = Position::where('workspace_id', $workspace->id)->first();
    expect($position->quantity)->toBeGreaterThan(0);
    expect(Order::find($order->id)->trades()->count())->toBe(1);
});
