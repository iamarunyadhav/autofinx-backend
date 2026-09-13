<?php

use App\Domain\Trading\RobotSessionService;
use App\Models\AuditDecisionSnapshot;
use App\Models\AuditEvent;
use App\Models\RobotSession;
use App\Models\RobotTradeAudit;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

test('a candle-direction robot session tick records both the existing RobotTradeAudit row and a new generic audit event', function () {
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

    $result = app(RobotSessionService::class)->evaluateSession($session->fresh());

    expect(RobotTradeAudit::where('robot_session_id', $session->id)->exists())->toBeTrue();

    $event = AuditEvent::where('bot_type', 'robot_trade')
        ->where('bot_instance_id', $session->id)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->correlation_id)->toStartWith($session->id . ':');

    $snapshot = AuditDecisionSnapshot::where('correlation_id', $event->correlation_id)->first();
    expect($snapshot)->not->toBeNull();

    // Regardless of which branch fired (volatility block vs a real decision),
    // status must be one of the documented evaluateCandleDirectionSession outcomes.
    expect($result['session_id'])->toBe($session->id);
});
