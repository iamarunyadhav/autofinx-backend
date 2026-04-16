<?php

use App\Domain\Intelligence\AiTradingStateService;
use App\Models\AiDecisionCycle;
use App\Models\AiTradingConfig;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('transitions pause and resume state cleanly', function () {
    $workspace = Workspace::factory()->create();
    $service = app(AiTradingStateService::class);

    $paused = $service->pause($workspace->id, 'BTCUSDT', \App\Models\AiTradingState::REASON_MANUAL_OPERATOR_PAUSE, 'manual');
    expect($paused->status)->toBe(\App\Models\AiTradingState::STATUS_PAUSED)
        ->and($paused->reason_code)->toBe(\App\Models\AiTradingState::REASON_MANUAL_OPERATOR_PAUSE);

    $resumed = $service->resume($workspace->id, 'BTCUSDT');
    expect($resumed->status)->toBe(\App\Models\AiTradingState::STATUS_ACTIVE)
        ->and($resumed->reason_code)->toBeNull()
        ->and($resumed->consecutive_order_failures)->toBe(0);
});

it('blocks decision cadence inside the configured interval window', function () {
    $workspace = Workspace::factory()->create();
    AiDecisionCycle::create([
        'id' => (string) Str::uuid(),
        'correlation_id' => 'cycle-1',
        'workspace_id' => $workspace->id,
        'trigger_type' => 'scheduled',
        'mode' => 'advisor',
        'symbol' => 'BTCUSDT',
        'status' => 'no_trade',
        'started_at' => now()->subMinutes(2),
        'finished_at' => now()->subMinutes(2),
    ]);

    $service = app(AiTradingStateService::class);
    $result = $service->enforceCadence($workspace->id, 'BTCUSDT', [
        'decision_interval_minutes' => 5,
        'max_decisions_per_hour' => 100,
    ]);

    expect($result['allowed'])->toBeFalse()
        ->and($result['reason_codes'])->toContain(\App\Models\AiTradingState::REASON_DECISION_INTERVAL_ACTIVE);
});

it('applies trade and loss cooldown timestamps', function () {
    $workspace = Workspace::factory()->create();
    $service = app(AiTradingStateService::class);

    $afterTrade = $service->recordTradeExecuted($workspace->id, 'BTCUSDT', [
        'cooldown_minutes_after_trade' => 10,
    ]);
    expect($afterTrade->status)->toBe(\App\Models\AiTradingState::STATUS_COOLING_DOWN)
        ->and($afterTrade->cooldown_until)->not->toBeNull();

    $afterLoss = $service->recordLoss($workspace->id, 'BTCUSDT', [
        'cooldown_minutes_after_loss' => 15,
    ], 'loss');
    expect($afterLoss->status)->toBe(\App\Models\AiTradingState::STATUS_COOLING_DOWN)
        ->and($afterLoss->cooldown_until)->not->toBeNull();
});
