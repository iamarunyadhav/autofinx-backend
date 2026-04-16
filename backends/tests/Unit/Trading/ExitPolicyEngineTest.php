<?php

use App\Domain\Trading\ExitPolicyEngine;
use App\Domain\Trading\Data\ExitDecision;
use App\Models\Position;
use App\Models\PositionExitState;

it('triggers a hard stop loss when trusted price breaches the stop', function () {
    $engine = app(ExitPolicyEngine::class);

    $position = new Position([
        'id' => 'position-1',
        'workspace_id' => 'workspace-1',
        'asset_code' => 'BTCUSDT',
        'provider' => 'binance',
        'qty' => 0.5,
        'avg_entry' => 100,
        'last_mark_price' => 94,
    ]);

    $state = new PositionExitState([
        'id' => 'state-1',
        'position_id' => 'position-1',
        'workspace_id' => 'workspace-1',
        'symbol' => 'BTCUSDT',
        'status' => PositionExitState::STATUS_OPEN,
        'managed_mode' => PositionExitState::MODE_ARMED,
        'stop_loss_price' => 95,
        'exit_policy_snapshot' => [
            'entry_price' => 100,
            'initial_qty' => 0.5,
        ],
    ]);

    $decision = $engine->evaluate($position, $state, [
        'price' => 94,
        'fresh' => true,
    ], [
        'correlation_id' => 'corr-1',
        'ops' => ['exchange_healthy' => true],
    ]);

    expect($decision->action)->toBe(ExitDecision::ACTION_FULL_EXIT)
        ->and($decision->reasonCode)->toBe('stop_loss_triggered')
        ->and($decision->quantityMode)->toBe('full');
});

it('fires only the next partial take profit level in a single cycle', function () {
    $engine = app(ExitPolicyEngine::class);

    $position = new Position([
        'qty' => 1.0,
        'avg_entry' => 100,
        'last_mark_price' => 112,
    ]);

    $state = new PositionExitState([
        'status' => PositionExitState::STATUS_OPEN,
        'managed_mode' => PositionExitState::MODE_ARMED,
        'partial_progress' => [
            'consumed_levels' => [],
            'cumulative_exit_qty' => 0,
        ],
        'exit_policy_snapshot' => [
            'entry_price' => 100,
            'initial_qty' => 1.0,
            'partial_take_profit_enabled' => true,
            'partial_take_profit_levels' => [5, 10],
            'partial_take_profit_sizes' => [25, 25],
        ],
    ]);

    $decision = $engine->evaluate($position, $state, [
        'price' => 112,
        'fresh' => true,
    ], [
        'correlation_id' => 'corr-2',
        'ops' => ['exchange_healthy' => true],
    ]);

    expect($decision->action)->toBe(ExitDecision::ACTION_PARTIAL_EXIT)
        ->and($decision->reasonCode)->toBe('partial_take_profit_triggered')
        ->and($decision->targetLevel)->toBe('5')
        ->and($decision->quantity)->toBe(0.25);
});

it('ratchets trailing state on favorable moves and later triggers on reversal', function () {
    $engine = app(ExitPolicyEngine::class);

    $position = new Position([
        'qty' => 1.0,
        'avg_entry' => 100,
        'last_mark_price' => 108,
    ]);

    $state = new PositionExitState([
        'status' => PositionExitState::STATUS_OPEN,
        'managed_mode' => PositionExitState::MODE_ARMED,
        'trailing_high_water_mark' => 105,
        'trailing_stop_price' => 99.75,
        'exit_policy_snapshot' => [
            'entry_price' => 100,
            'initial_qty' => 1.0,
            'trailing_stop_enabled' => true,
            'trailing_stop_pct' => 5,
        ],
    ]);

    $updateDecision = $engine->evaluate($position, $state, [
        'price' => 110,
        'fresh' => true,
    ], [
        'correlation_id' => 'corr-3',
        'ops' => ['exchange_healthy' => true],
    ]);

    expect($updateDecision->action)->toBe(ExitDecision::ACTION_NO_ACTION)
        ->and($updateDecision->stateUpdates['trailing_high_water_mark'])->toBe(110.0)
        ->and($updateDecision->stateUpdates['trailing_stop_price'])->toBe(104.5);

    $state->trailing_high_water_mark = 110;
    $state->trailing_stop_price = 104.5;

    $triggerDecision = $engine->evaluate($position, $state, [
        'price' => 104.4,
        'fresh' => true,
    ], [
        'correlation_id' => 'corr-4',
        'ops' => ['exchange_healthy' => true],
    ]);

    expect($triggerDecision->action)->toBe(ExitDecision::ACTION_FULL_EXIT)
        ->and($triggerDecision->reasonCode)->toBe('trailing_stop_triggered');
});

it('blocks price-driven exits when market data is stale', function () {
    $engine = app(ExitPolicyEngine::class);

    $position = new Position([
        'qty' => 0.5,
        'avg_entry' => 100,
    ]);

    $state = new PositionExitState([
        'status' => PositionExitState::STATUS_OPEN,
        'managed_mode' => PositionExitState::MODE_ARMED,
        'stop_loss_price' => 95,
        'exit_policy_snapshot' => [
            'entry_price' => 100,
            'initial_qty' => 0.5,
        ],
    ]);

    $decision = $engine->evaluate($position, $state, [
        'price' => 94,
        'fresh' => false,
    ], [
        'correlation_id' => 'corr-5',
        'ops' => ['exchange_healthy' => true],
    ]);

    expect($decision->action)->toBe(ExitDecision::ACTION_BLOCKED)
        ->and($decision->reasonCode)->toBe('stale_market_data');
});

it('forces a time stop exit once the deadline is reached', function () {
    $engine = app(ExitPolicyEngine::class);

    $position = new Position([
        'qty' => 0.75,
        'avg_entry' => 100,
    ]);

    $state = new PositionExitState([
        'status' => PositionExitState::STATUS_OPEN,
        'managed_mode' => PositionExitState::MODE_ARMED,
        'time_stop_at' => now()->subMinute(),
        'exit_policy_snapshot' => [
            'entry_price' => 100,
            'initial_qty' => 0.75,
        ],
    ]);

    $decision = $engine->evaluate($position, $state, [
        'price' => 101,
        'fresh' => true,
    ], [
        'correlation_id' => 'corr-6',
        'ops' => ['exchange_healthy' => true],
    ]);

    expect($decision->action)->toBe(ExitDecision::ACTION_FULL_EXIT)
        ->and($decision->reasonCode)->toBe('time_stop_reached');
});
