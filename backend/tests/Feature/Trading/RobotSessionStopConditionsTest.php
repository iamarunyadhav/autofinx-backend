<?php

use App\Domain\Trading\RobotSessionService;
use App\Models\QuickTrade;
use App\Models\RobotSession;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('robot session stops after configured consecutive losses', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $session = RobotSession::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'name' => 'Loss stop robot',
        'symbol' => 'GOLD_OTC',
        'asset_class' => 'METALS',
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'indicator_set' => ['RSI', 'MACD'],
        'strategy_profile' => 'balanced',
        'base_stake' => 10,
        'loss_limit' => 50,
        'max_consecutive_losses' => 2,
        'max_active_trades' => 1,
        'confidence_threshold' => 60,
        'mode' => 'paper',
        'status' => 'RUNNING',
    ]);

    foreach ([['id' => (string) Str::uuid(), 'settled_at' => '2026-03-10 10:01:00'], ['id' => (string) Str::uuid(), 'settled_at' => '2026-03-10 10:03:00']] as $row) {
        QuickTrade::create([
            'id' => $row['id'],
            'workspace_id' => $workspace->id,
            'robot_session_id' => $session->id,
            'symbol' => 'GOLD_OTC',
            'asset_class' => 'METALS',
            'side' => 'buy',
            'stake_amount' => 10,
            'timeframe' => '1m',
            'expiry_seconds' => 60,
            'payout_ratio' => 0.91,
            'mode' => 'paper',
            'source' => 'robot_trade',
            'entry_price' => 100,
            'exit_price' => 95,
            'status' => 'lost',
            'profit_loss' => -10,
            'payout_amount' => 0,
            'entry_at' => CarbonImmutable::parse('2026-03-10 10:00:00'),
            'expires_at' => CarbonImmutable::parse('2026-03-10 10:01:00'),
            'settled_at' => CarbonImmutable::parse($row['settled_at']),
        ]);
    }

    $refreshed = app(RobotSessionService::class)->refreshPerformance($session);

    expect($refreshed->status)->toBe('STOPPED')
        ->and($refreshed->consecutive_losses)->toBe(2)
        ->and($refreshed->last_error)->toContain('consecutive losses');
});
