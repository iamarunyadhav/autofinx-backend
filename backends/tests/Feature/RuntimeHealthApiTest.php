<?php

use App\Models\MarketFeedStatus;
use App\Models\RatioBotConfig;
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

test('runtime health does not mark all required feed symbols healthy from one fresh row', function () {
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

    foreach (['BTCUSDT', 'ETHUSDT'] as $symbol) {
        RatioBotConfig::create([
            'workspace_id' => $workspace->id,
            'symbol' => $symbol,
            'market_type' => 'spot',
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
        ]);
    }

    MarketFeedStatus::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'symbol' => 'BTCUSDT',
        'symbol_id' => (string) Str::uuid(),
        'exchange' => 'binance',
        'market_type' => 'spot',
        'source' => 'websocket',
        'status' => 'healthy',
        'last_tick_at' => now(),
        'last_price' => 78000,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/runtime/health?workspace_id='.$workspace->id)
        ->assertOk()
        ->assertJsonPath('future_trade.live_feed', 'red')
        ->assertJsonPath('future_trade.live_feed_detail.reason', 'market_feed_stale_or_missing_required_symbol');
});

test('ratio_bot.live_feed is green when no ratio bot configs are enabled', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/runtime/health?workspace_id='.$workspace->id)
        ->assertOk()
        ->assertJsonPath('ratio_bot.live_feed', 'green')
        ->assertJsonPath('ratio_bot.live_feed_detail.reason', 'no_enabled_feed_dependent_bots');
});

test('ratio_bot.live_feed goes red for an enabled spot bot with no fresh feed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
    ]);

    RatioBotConfig::create([
        'workspace_id' => $workspace->id,
        'symbol' => 'BTCUSDT',
        'market_type' => 'spot',
        'execution_mode' => 'sandbox',
        'enabled' => true,
        'sell_threshold_ratio' => 0.02,
        'buy_threshold_ratio' => 0.01,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/runtime/health?workspace_id='.$workspace->id)
        ->assertOk()
        ->assertJsonPath('ratio_bot.live_feed', 'red')
        ->assertJsonPath('ratio_bot.live_feed_detail.reason', 'market_feed_stale_or_missing_required_symbol');
});

test('ratio_bot.live_feed is green when the spot symbol has a fresh websocket redis tick', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
    ]);

    RatioBotConfig::create([
        'workspace_id' => $workspace->id,
        'symbol' => 'BTCUSDT',
        'market_type' => 'spot',
        'execution_mode' => 'sandbox',
        'enabled' => true,
        'sell_threshold_ratio' => 0.02,
        'buy_threshold_ratio' => 0.01,
    ]);

    app(\App\Domain\Market\RealtimeMarketFeedService::class)->ingest([
        'stream' => 'btcusdt@trade',
        'data' => [
            'e' => 'trade',
            's' => 'BTCUSDT',
            'p' => '65000.50',
            'E' => now()->getTimestampMs(),
        ],
    ], 'spot', false);

    try {
        $this->actingAs($user)
            ->getJson('/api/v1/runtime/health?workspace_id='.$workspace->id)
            ->assertOk()
            ->assertJsonPath('ratio_bot.live_feed', 'green');
    } finally {
        foreach ([
            'autofinx:market:binance:BTCUSDT:quote',
            'autofinx:market:binance:BTCUSDT:health:QUOTE',
            'market:live:BTCUSDT',
            'live-feed:binance:BTCUSDT',
            'price:binance:BTCUSDT',
            'ts:binance:BTCUSDT',
        ] as $key) {
            try {
                \Illuminate\Support\Facades\Redis::del($key);
            } catch (\Throwable) {
            }
        }
    }
});
