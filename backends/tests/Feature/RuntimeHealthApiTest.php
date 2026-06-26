<?php

use App\Models\MarketFeedStatus;
use App\Models\SchedulerRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('runtime health endpoint returns trading module traffic light status', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
    ]);

    foreach (['quick-trades:settle', 'robot-sessions:evaluate', 'future-trade:tick', 'future-trade:user-stream', 'future-trade:reconcile'] as $command) {
        SchedulerRun::create([
            'id' => (string) Str::uuid(),
            'command' => $command,
            'started_at' => now()->subSeconds(5),
            'finished_at' => now(),
            'status' => 'completed',
        ]);
    }

    MarketFeedStatus::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'symbol_id' => (string) Str::uuid(),
        'exchange' => 'binance',
        'market_type' => 'futures',
        'source' => 'websocket',
        'status' => 'healthy',
        'last_tick_at' => now(),
        'last_price' => 2000,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/runtime/health?workspace_id='.$workspace->id)
        ->assertOk()
        ->assertJsonPath('overall', 'green')
        ->assertJsonPath('quick_trade.settler', 'green')
        ->assertJsonPath('robot_trade.evaluator', 'green')
        ->assertJsonPath('future_trade.execution_queue', 'green')
        ->assertJsonPath('future_trade.daily_loss_lock', false)
        ->assertJsonStructure([
            'quick_trade' => ['settler_age_seconds'],
            'robot_trade' => ['evaluator_age_seconds'],
            'future_trade' => ['live_feed_age_seconds'],
            'supervisor' => [
                'quick_settler',
                'robot_evaluator',
                'live_feed',
                'user_stream',
                'tick_loop',
                'queue_critical',
            ],
        ]);
});
