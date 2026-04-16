<?php

use App\Domain\Intelligence\AiTradePolicyService;
use App\Domain\Intelligence\Data\AiTradeProposal;
use App\Domain\Ops\LiveExecutionGuard;
use App\Models\AiDecision;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('blocks unsupported symbols in operator policy', function () {
    $guard = Mockery::mock(LiveExecutionGuard::class);
    $service = new AiTradePolicyService($guard);

    $proposal = AiTradeProposal::fromArray([
        'action' => 'BUY',
        'symbol' => 'ETHUSDT',
        'confidence' => 0.7,
        'reasoning_summary' => 'test',
        'size_pct' => 1,
        'stop_loss_pct' => 0.8,
        'take_profit_pct' => 1.6,
        'time_stop_minutes' => 90,
        'requires_human_approval' => false,
        'warnings' => [],
    ]);

    $result = $service->evaluate('ws-1', [
        'enabled' => true,
        'mode' => 'restricted_auto',
        'exchange' => 'binance',
        'market_type' => 'spot',
        'allowed_symbols' => ['BTCUSDT'],
        'blocked_symbols' => [],
        'max_trade_size_pct' => 2,
        'min_confidence' => 0.5,
        'require_stop_loss' => true,
        'require_take_profit' => true,
        'require_time_stop' => true,
        'auto_trade_enabled' => true,
    ], [
        'blockers' => [],
        'price' => ['latest' => 30000],
        'balances' => ['capital_base' => 1000],
    ], $proposal);

    expect($result['allowed'])->toBeFalse()
        ->and($result['reason_codes'])->toContain('symbol_not_in_operator_allowlist');
});

it('blocks low confidence auto trade proposals', function () {
    $guard = Mockery::mock(LiveExecutionGuard::class);
    $service = new AiTradePolicyService($guard);

    $proposal = AiTradeProposal::fromArray([
        'action' => 'BUY',
        'symbol' => 'BTCUSDT',
        'confidence' => 0.45,
        'reasoning_summary' => 'test',
        'size_pct' => 1,
        'stop_loss_pct' => 0.8,
        'take_profit_pct' => 1.6,
        'time_stop_minutes' => 90,
        'requires_human_approval' => false,
        'warnings' => [],
    ]);

    $result = $service->evaluate('ws-1', [
        'enabled' => true,
        'mode' => 'restricted_auto',
        'exchange' => 'binance',
        'market_type' => 'spot',
        'allowed_symbols' => [],
        'blocked_symbols' => [],
        'max_trade_size_pct' => 2,
        'min_confidence' => 0.6,
        'require_stop_loss' => true,
        'require_take_profit' => true,
        'require_time_stop' => true,
        'auto_trade_enabled' => true,
    ], [
        'blockers' => [],
        'price' => ['latest' => 30000],
        'balances' => ['capital_base' => 1000],
    ], $proposal);

    expect($result['allowed'])->toBeFalse()
        ->and($result['reason_codes'])->toContain('confidence_below_auto_threshold');
});

it('blocks missing stop take and time stop fields in restricted auto mode', function () {
    $guard = Mockery::mock(LiveExecutionGuard::class);
    $service = new AiTradePolicyService($guard);

    $proposal = AiTradeProposal::fromArray([
        'action' => 'BUY',
        'symbol' => 'BTCUSDT',
        'confidence' => 0.7,
        'reasoning_summary' => 'test',
        'size_pct' => 1,
        'stop_loss_pct' => 0,
        'take_profit_pct' => 0,
        'time_stop_minutes' => 0,
        'requires_human_approval' => false,
        'warnings' => [],
    ]);

    $result = $service->evaluate('ws-1', [
        'enabled' => true,
        'mode' => 'restricted_auto',
        'exchange' => 'binance',
        'market_type' => 'spot',
        'allowed_symbols' => [],
        'blocked_symbols' => [],
        'max_trade_size_pct' => 2,
        'min_confidence' => 0.5,
        'require_stop_loss' => true,
        'require_take_profit' => true,
        'require_time_stop' => true,
        'auto_trade_enabled' => true,
    ], [
        'blockers' => [],
        'price' => ['latest' => 30000],
        'balances' => ['capital_base' => 1000],
    ], $proposal);

    expect($result['allowed'])->toBeFalse()
        ->and($result['reason_codes'])->toContain('stop_loss_required');
});

it('blocks when pending decisions for the symbol already reached the operator limit', function () {
    $guard = Mockery::mock(LiveExecutionGuard::class);
    $service = new AiTradePolicyService($guard);
    $workspace = Workspace::factory()->create();

    AiDecision::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'symbol' => 'BTCUSDT',
        'action' => 'BUY',
        'final_status' => AiDecision::STATUS_PENDING_APPROVAL,
    ]);

    $proposal = AiTradeProposal::fromArray([
        'action' => 'BUY',
        'symbol' => 'BTCUSDT',
        'confidence' => 0.7,
        'reasoning_summary' => 'test',
        'size_pct' => 1,
        'stop_loss_pct' => 0.8,
        'take_profit_pct' => 1.6,
        'time_stop_minutes' => 90,
        'requires_human_approval' => false,
        'warnings' => [],
    ]);

    $result = $service->evaluate($workspace->id, [
        'enabled' => true,
        'mode' => 'manual_approval',
        'exchange' => 'binance',
        'market_type' => 'spot',
        'allowed_symbols' => [],
        'blocked_symbols' => [],
        'max_trade_size_pct' => 2,
        'min_confidence' => 0.5,
        'cooldown_minutes' => 0,
        'max_pending_decisions_per_symbol' => 1,
        'max_decisions_per_hour_per_symbol' => 12,
        'require_stop_loss' => true,
        'require_take_profit' => true,
        'require_time_stop' => true,
        'auto_trade_enabled' => false,
    ], [
        'blockers' => [],
        'price' => ['latest' => 30000],
        'balances' => ['capital_base' => 1000],
    ], $proposal);

    expect($result['allowed'])->toBeFalse()
        ->and($result['reason_codes'])->toContain('pending_decision_limit_reached');
});

it('blocks when the symbol is still inside the operator cooldown window', function () {
    $guard = Mockery::mock(LiveExecutionGuard::class);
    $service = new AiTradePolicyService($guard);
    $workspace = Workspace::factory()->create();

    AiDecision::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'symbol' => 'BTCUSDT',
        'action' => 'BUY',
        'final_status' => AiDecision::STATUS_EXECUTED,
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    $proposal = AiTradeProposal::fromArray([
        'action' => 'BUY',
        'symbol' => 'BTCUSDT',
        'confidence' => 0.7,
        'reasoning_summary' => 'test',
        'size_pct' => 1,
        'stop_loss_pct' => 0.8,
        'take_profit_pct' => 1.6,
        'time_stop_minutes' => 90,
        'requires_human_approval' => false,
        'warnings' => [],
    ]);

    $result = $service->evaluate($workspace->id, [
        'enabled' => true,
        'mode' => 'restricted_auto',
        'exchange' => 'binance',
        'market_type' => 'spot',
        'allowed_symbols' => [],
        'blocked_symbols' => [],
        'max_trade_size_pct' => 2,
        'min_confidence' => 0.5,
        'cooldown_minutes' => 15,
        'max_pending_decisions_per_symbol' => 1,
        'max_decisions_per_hour_per_symbol' => 12,
        'require_stop_loss' => true,
        'require_take_profit' => true,
        'require_time_stop' => true,
        'auto_trade_enabled' => true,
    ], [
        'blockers' => [],
        'price' => ['latest' => 30000],
        'balances' => ['capital_base' => 1000],
    ], $proposal);

    expect($result['allowed'])->toBeFalse()
        ->and($result['reason_codes'])->toContain('symbol_cooldown_active');
});
