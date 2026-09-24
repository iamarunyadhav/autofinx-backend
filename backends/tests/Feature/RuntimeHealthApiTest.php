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

/*
 * Isolation (Phase 1 item 3). TradingRuntimeHealthService reads the market
 * websocket heartbeat FILES under storage_path('framework/...') and the
 * quick-settler/robot-evaluator runtime heartbeat files next to them. On a
 * dev machine with a real `market:ws-consume` running, those files are
 * fresh; in a clean sandbox they are missing or stale -- so the same test
 * asserted a different reason depending on the machine. (Redis is already
 * isolated: phpunit.xml pins REDIS_DB=15 and Tests\TestCase clears the
 * market-feed keys after every test.)
 *
 * Every test here now runs against an empty, private storage directory, so
 * the ONLY heartbeat state a test sees is the one it writes itself.
 */
beforeEach(function () {
    $this->isolatedStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'afx-runtime-health-'.Str::random(12);
    mkdir($this->isolatedStorage.DIRECTORY_SEPARATOR.'framework', 0777, true);
    $this->app->useStoragePath($this->isolatedStorage);
});

afterEach(function () {
    foreach (glob($this->isolatedStorage.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->isolatedStorage.DIRECTORY_SEPARATOR.'framework');
    @rmdir($this->isolatedStorage);
});

/** Writes a spot market-ws heartbeat file into the isolated storage dir. */
function writeSpotMarketHeartbeat(int $epoch, array $perSymbol = []): void
{
    file_put_contents(storage_path('framework/autofinx-market-ws-heartbeat-spot.json'), json_encode([
        'last_message_epoch' => $epoch,
        'last_message_at' => date('c', $epoch),
        'symbol' => array_key_first($perSymbol) ?? 'SOLUSDT',
        'market_type' => 'spot',
        'message_count' => 10,
        'reconnect_count' => 0,
        'per_symbol' => $perSymbol,
    ]));
}

function enabledSpotRatioBotWorkspace(): array
{
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

    return [$user, $workspace];
}

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

// The three honest "no fresh feed" states for a required spot symbol. The
// service evaluates the heartbeat file BEFORE demoting for a missing
// required symbol (TradingRuntimeHealthService::marketFeed ->
// heartbeatFeed -> demoteForMissingRequiredFeeds), so each state has its
// own, more specific reason; all three are red.

test('ratio_bot.live_feed goes red with no_market_feed_tick when no spot websocket worker has ever written a heartbeat', function () {
    [$user, $workspace] = enabledSpotRatioBotWorkspace();

    $this->actingAs($user)
        ->getJson('/api/v1/runtime/health?workspace_id='.$workspace->id)
        ->assertOk()
        ->assertJsonPath('ratio_bot.live_feed', 'red')
        ->assertJsonPath('ratio_bot.live_feed_detail.reason', 'no_market_feed_tick');
});

test('ratio_bot.live_feed goes red with live_feed_heartbeat_stale when the spot worker heartbeat is stale', function () {
    [$user, $workspace] = enabledSpotRatioBotWorkspace();
    writeSpotMarketHeartbeat(time() - 3600, ['BTCUSDT' => ['last_message_epoch' => time() - 3600, 'last_message_at' => date('c', time() - 3600)]]);

    $this->actingAs($user)
        ->getJson('/api/v1/runtime/health?workspace_id='.$workspace->id)
        ->assertOk()
        ->assertJsonPath('ratio_bot.live_feed', 'red')
        ->assertJsonPath('ratio_bot.live_feed_detail.reason', 'live_feed_heartbeat_stale');
});

test('ratio_bot.live_feed goes red for an enabled spot bot with no fresh feed even while the worker is alive for another symbol', function () {
    [$user, $workspace] = enabledSpotRatioBotWorkspace();
    // Worker alive (fresh aggregate) but BTCUSDT -- the required symbol --
    // has no fresh Redis tick and no per-symbol heartbeat entry.
    writeSpotMarketHeartbeat(time(), ['SOLUSDT' => ['last_message_epoch' => time(), 'last_message_at' => date('c')]]);

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
