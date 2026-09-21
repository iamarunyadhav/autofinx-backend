<?php

use App\Models\FutureTradeBotConfig;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

/**
 * Regression coverage for the confirmed real-money bug:
 * FutureTradeRuntimeStatusCommand::liveFeedStatus() computed `stale` purely
 * from the futures heartbeat file's TOP-LEVEL aggregate last_message_epoch
 * (whichever symbol happened to tick most recently across every stream the
 * daemon subscribes to), with no per-symbol awareness and no cross-check
 * against any other freshness source — unlike TradingRuntimeHealthService,
 * this command had nothing to demote a wrongly-fresh read. A healthy
 * unrelated symbol could mask a genuinely stale REQUIRED symbol (the one an
 * enabled Future Trade bot actually needs), and vice versa.
 */
function ftRuntimeStatusHeartbeatPath(): string
{
    return storage_path('framework/autofinx-market-ws-heartbeat-futures.json');
}

beforeEach(function () {
    $path = ftRuntimeStatusHeartbeatPath();
    $this->ftHeartbeatBackup = file_exists($path) ? file_get_contents($path) : null;
});

afterEach(function () {
    $path = ftRuntimeStatusHeartbeatPath();
    if ($this->ftHeartbeatBackup !== null) {
        file_put_contents($path, $this->ftHeartbeatBackup);
    } elseif (file_exists($path)) {
        @unlink($path);
    }
});

test('live_feed.stale is true when the required symbol is stale even though an unrelated symbol just ticked', function () {
    $workspace = Workspace::factory()->create();
    FutureTradeBotConfig::create([
        'workspace_id' => $workspace->id,
        'bot_name' => 'Futures Bot',
        'enabled' => true,
        'settings' => array_merge((array) config('future_trade.defaults'), ['symbols' => ['BTCUSDT']]),
    ]);

    $now = time();
    file_put_contents(ftRuntimeStatusHeartbeatPath(), json_encode([
        'last_message_epoch' => $now, // top-level aggregate: very fresh...
        'last_message_at' => date('c', $now),
        'symbol' => 'ETHUSDT', // ...but from a symbol this workspace never requires
        'market_type' => 'futures',
        'message_count' => 100,
        'reconnect_count' => 0,
        'connected_at' => date('c', $now),
        'per_symbol' => [
            'BTCUSDT' => [
                'last_message_epoch' => $now - 500, // the actually-required symbol: genuinely stale
                'last_message_at' => date('c', $now - 500),
            ],
            'ETHUSDT' => [
                'last_message_epoch' => $now,
                'last_message_at' => date('c', $now),
            ],
        ],
    ]));

    Artisan::call('future-trade:runtime-status', ['--json' => true, '--workspace' => (string) $workspace->id]);
    $status = json_decode(Artisan::output(), true);

    expect($status['live_feed']['stale'])->toBeTrue();
    expect($status['live_feed']['required_symbols'])->toBe(['BTCUSDT']);
    expect($status['live_feed']['per_symbol']['BTCUSDT']['stale'])->toBeTrue();
    expect($status['live_feed']['per_symbol']['BTCUSDT']['age_seconds'])->toBeGreaterThanOrEqual(490);
});

test('live_feed.stale is false when the required symbol itself is fresh even though an unrelated symbol went quiet', function () {
    $workspace = Workspace::factory()->create();
    FutureTradeBotConfig::create([
        'workspace_id' => $workspace->id,
        'bot_name' => 'Futures Bot',
        'enabled' => true,
        'settings' => array_merge((array) config('future_trade.defaults'), ['symbols' => ['BTCUSDT']]),
    ]);

    $now = time();
    file_put_contents(ftRuntimeStatusHeartbeatPath(), json_encode([
        'last_message_epoch' => $now - 500, // top-level aggregate: stale (ETHUSDT went quiet)...
        'last_message_at' => date('c', $now - 500),
        'symbol' => 'ETHUSDT',
        'market_type' => 'futures',
        'message_count' => 100,
        'reconnect_count' => 0,
        'connected_at' => date('c', $now),
        'per_symbol' => [
            'BTCUSDT' => [
                'last_message_epoch' => $now, // ...but the required symbol is fresh right now
                'last_message_at' => date('c', $now),
            ],
            'ETHUSDT' => [
                'last_message_epoch' => $now - 500,
                'last_message_at' => date('c', $now - 500),
            ],
        ],
    ]));

    Artisan::call('future-trade:runtime-status', ['--json' => true, '--workspace' => (string) $workspace->id]);
    $status = json_decode(Artisan::output(), true);

    expect($status['live_feed']['stale'])->toBeFalse();
    expect($status['live_feed']['per_symbol']['BTCUSDT']['stale'])->toBeFalse();
});

test('live_feed falls back to the aggregate when no Future Trade bot requires a specific symbol yet', function () {
    $now = time();
    file_put_contents(ftRuntimeStatusHeartbeatPath(), json_encode([
        'last_message_epoch' => $now,
        'last_message_at' => date('c', $now),
        'symbol' => 'BTCUSDT',
        'market_type' => 'futures',
        'message_count' => 5,
        'reconnect_count' => 0,
        'connected_at' => date('c', $now),
        'per_symbol' => [
            'BTCUSDT' => ['last_message_epoch' => $now, 'last_message_at' => date('c', $now)],
        ],
    ]));

    $workspace = Workspace::factory()->create();

    Artisan::call('future-trade:runtime-status', ['--json' => true, '--workspace' => (string) $workspace->id]);
    $status = json_decode(Artisan::output(), true);

    expect($status['live_feed']['required_symbols'])->toBe([]);
    expect($status['live_feed']['per_symbol'])->toBe([]);
    expect($status['live_feed']['stale'])->toBeFalse();
});
