<?php

use App\Models\MarketCandle;
use App\Models\Strategy;
use App\Models\StrategyInstance;
use App\Models\StrategyParam;
use App\Models\Symbol;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('rejects backtests when there is no stored real candle history', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $strategy = Strategy::create([
        'id' => (string) Str::uuid(),
        'name' => 'Grid Trader (paper)',
        'slug' => 'grid',
        'enabled' => true,
        'params_schema' => [],
    ]);

    $instance = StrategyInstance::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'strategy_id' => $strategy->id,
        'name' => 'Grid Backtest',
        'mode' => 'paper',
        'status' => 'idle',
    ]);

    StrategyParam::create([
        'id' => (string) Str::uuid(),
        'instance_id' => $instance->id,
        'key' => 'symbol',
        'value' => 'BTCUSDT',
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/strategy-instances/{$instance->id}/backtest", [
            'symbol' => 'BTCUSDT',
            'candle_timeframe' => '1h',
            'limit' => 120,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Not enough stored market candles for BTCUSDT 1h. Backtests now require real candle history and will not use synthetic data.');
});

it('runs a real backtest against stored market candles', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $strategy = Strategy::create([
        'id' => (string) Str::uuid(),
        'name' => 'Grid Trader (paper)',
        'slug' => 'grid',
        'enabled' => true,
        'params_schema' => [],
    ]);

    $instance = StrategyInstance::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'strategy_id' => $strategy->id,
        'name' => 'Grid Backtest',
        'mode' => 'paper',
        'status' => 'idle',
    ]);

    foreach ([
        'symbol' => 'BTCUSDT',
        'base_price' => 100,
        'grid_spacing_pct' => 0.5,
        'grid_levels' => 4,
    ] as $key => $value) {
        StrategyParam::create([
            'id' => (string) Str::uuid(),
            'instance_id' => $instance->id,
            'key' => $key,
            'value' => $value,
        ]);
    }

    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'BTCUSDT',
        'type' => 'crypto',
    ]);

    $prices = [];
    for ($index = 0; $index < 80; $index++) {
        $prices[] = match ($index % 4) {
            0 => 100.0,
            1 => 101.2,
            2 => 98.8,
            default => 100.6,
        };
    }

    foreach ($prices as $index => $price) {
        MarketCandle::create([
            'id' => (string) Str::uuid(),
            'symbol_id' => $symbol->id,
            'timeframe' => '1h',
            'opens_at' => Carbon::parse('2026-01-01 00:00:00')->addHours($index),
            'open' => $price,
            'high' => $price + 0.4,
            'low' => $price - 0.4,
            'close' => $price,
            'volume' => 100 + $index,
        ]);
    }

    $response = $this->actingAs($user)
        ->postJson("/api/v1/strategy-instances/{$instance->id}/backtest", [
            'symbol' => 'BTCUSDT',
            'candle_timeframe' => '1h',
            'limit' => 80,
        ])
        ->assertOk();

    $payload = $response->json();

    expect($payload['meta']['symbol'])->toBe('BTCUSDT')
        ->and($payload['meta']['candles'])->toBe(80)
        ->and((float) $payload['summary']['initialCapital'])->toBe(10000.0)
        ->and($payload['summary']['totalTrades'])->toBeGreaterThan(0)
        ->and($payload['summary']['endingEquity'])->not->toBe(10000.0);
});
