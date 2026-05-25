<?php

use App\Domain\FutureTrade\FutureTradeUserStreamService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function userStreamConfig(array $settings = []): FutureTradeBotConfig
{
    return FutureTradeBotConfig::create([
        'workspace_id' => (string) \Illuminate\Support\Str::uuid(),
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => false,
        'enable_live_trading' => true,
        'enabled' => true,
        'settings' => array_merge(config('future_trade.defaults'), [
            'liveTrading' => true,
            'paperTrading' => false,
            'forceTestnet' => true,
            'envMode' => 'testnet',
            'envTestnetEnabled' => true,
            'testnetApiKey' => 'test-key',
            'testnetApiSecret' => 'test-secret',
            'testnetApiUrl' => 'https://testnet.binancefuture.local',
        ], $settings),
    ]);
}

test('snapshot returns NOT_CONFIGURED when credentials are missing', function () {
    $config = userStreamConfig([
        'testnetApiKey' => '',
        'testnetApiSecret' => '',
    ]);
    $svc = app(FutureTradeUserStreamService::class);
    $snap = $svc->snapshot($config);
    expect($snap['configured'])->toBeFalse()
        ->and($snap['status'])->toBe(FutureTradeUserStreamService::STATUS_NOT_CONFIGURED)
        ->and($snap['fresh'])->toBeFalse();
});

test('snapshot reports fresh when recordEvent ran moments ago', function () {
    $config = userStreamConfig();
    $svc = app(FutureTradeUserStreamService::class);
    $svc->recordEvent($config, ['e' => 'ACCOUNT_UPDATE']);
    $snap = $svc->snapshot($config);
    expect($snap['configured'])->toBeTrue()
        ->and($snap['status'])->toBe(FutureTradeUserStreamService::STATUS_CONNECTED)
        ->and($snap['fresh'])->toBeTrue()
        ->and((int) ($snap['ageSeconds'] ?? 999))->toBeLessThanOrEqual(2);
});

test('snapshot reports fresh when only heartbeat ran and no account event arrived yet', function () {
    $config = userStreamConfig();
    $svc = app(FutureTradeUserStreamService::class);
    $svc->recordHeartbeat($config, 'connect');

    $snap = $svc->snapshot($config);
    expect($snap['configured'])->toBeTrue()
        ->and($snap['status'])->toBe(FutureTradeUserStreamService::STATUS_CONNECTED)
        ->and($snap['fresh'])->toBeTrue()
        ->and($snap['eventFresh'])->toBeFalse()
        ->and($snap['heartbeatFresh'])->toBeTrue()
        ->and((int) ($snap['heartbeatAgeSeconds'] ?? 999))->toBeLessThanOrEqual(2);
});

test('ingestEvent on ORDER_TRADE_UPDATE with FILLED exit closes the linked logical_trade', function () {
    $config = userStreamConfig();
    $svc = app(FutureTradeUserStreamService::class);

    $trade = FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'workspace_id' => (string) $config->workspace_id,
        'symbol' => 'ETHUSDT',
        'side' => 'SHORT',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN,
        'mode' => 'LIVE_TESTNET',
        'signal_key' => 'sk-ws-1',
        'entry_client_order_id' => 'ftx-entry-abc',
        'exit_client_order_id' => 'ftx-exit-xyz',
        'entry_price' => 2300,
        'quantity' => 0.05,
        'opened_at' => now(),
    ]);

    $payload = [
        'e' => 'ORDER_TRADE_UPDATE',
        'o' => [
            's' => 'ETHUSDT',
            'c' => 'ftx-exit-xyz',
            'i' => '777',
            'X' => 'FILLED',
            'x' => 'TRADE',
            'S' => 'BUY',
            'ap' => 2275, // average exit price
            'z' => 0.05,
            'rp' => 1.25, // realized PnL from Binance
            'n' => 0.02,  // commission
        ],
    ];

    expect($svc->ingestEvent($config, $payload))->toBeTrue();
    $trade->refresh();
    expect($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED)
        ->and((float) $trade->exit_price)->toEqualWithDelta(2275.0, 0.0001)
        ->and((float) $trade->net_pnl)->toBeGreaterThan(0); // SHORT into a lower price = profit
});

test('ingest endpoint rejects requests without a valid token', function () {
    config(['app.env' => 'production']);
    \Illuminate\Support\Facades\Config::set('app.env', 'production');
    putenv('USER_STREAM_INGEST_TOKEN=expected-secret');

    $config = userStreamConfig();
    $response = $this->postJson('/api/v1/future-trade/user-stream/ingest', [
        'e' => 'ACCOUNT_UPDATE',
    ], [
        'X-FT-Config-Id' => $config->id,
        'X-User-Stream-Token' => 'wrong-secret',
    ]);
    $response->assertStatus(401);
    putenv('USER_STREAM_INGEST_TOKEN');
});

test('ingest endpoint accepts events when token matches and updates heartbeat', function () {
    putenv('USER_STREAM_INGEST_TOKEN=expected-secret');
    $config = userStreamConfig();
    $svc = app(FutureTradeUserStreamService::class);

    $response = $this->postJson('/api/v1/future-trade/user-stream/ingest', [
        'e' => 'ACCOUNT_UPDATE',
    ], [
        'X-FT-Config-Id' => $config->id,
        'X-User-Stream-Token' => 'expected-secret',
    ]);
    $response->assertOk()->assertJsonPath('ok', true);

    $snap = $svc->snapshot($config->fresh());
    expect($snap['fresh'])->toBeTrue()
        ->and($snap['status'])->toBe(FutureTradeUserStreamService::STATUS_CONNECTED);
    putenv('USER_STREAM_INGEST_TOKEN');
});
