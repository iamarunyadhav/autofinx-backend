<?php

use App\Domain\Trading\QuickTradeService;
use App\Models\AuditDecisionSnapshot;
use App\Models\AuditEvent;
use App\Models\RobotSession;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

test('with audit_research disabled, quick trade placement writes zero audit rows', function () {
    config(['audit_research.enabled' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    app(QuickTradeService::class)->place([
        'workspace_id' => $workspace->id,
        'symbol' => 'GOLD_OTC',
        'side' => 'buy',
        'stake_amount' => 10,
        'timeframe' => '1m',
        'expiry_seconds' => 5,
        'mode' => 'paper',
        'indicator_set' => ['RSI'],
    ], $user->id);

    expect(AuditEvent::count())->toBe(0)
        ->and(AuditDecisionSnapshot::count())->toBe(0);
});

test('with audit_research disabled, a candle-direction robot session tick writes zero audit rows', function () {
    config(['audit_research.enabled' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $session = RobotSession::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'name' => 'Candle direction robot',
        'symbol' => 'GOLD_OTC',
        'asset_class' => 'METALS',
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'indicator_set' => ['RSI', 'MACD'],
        'strategy_profile' => 'balanced',
        'base_stake' => 10,
        'max_active_trades' => 1,
        'confidence_threshold' => 60,
        'mode' => 'paper',
        'status' => 'RUNNING',
        'bot_mode' => 'candle_direction',
    ]);

    app(\App\Domain\Trading\RobotSessionService::class)->evaluateSession($session->fresh());

    expect(AuditEvent::where('bot_type', 'robot_trade')->count())->toBe(0)
        ->and(AuditDecisionSnapshot::count())->toBe(0);
});
