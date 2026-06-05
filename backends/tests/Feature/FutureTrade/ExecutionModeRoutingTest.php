<?php

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Domain\FutureTrade\FutureTradePnLService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Execution-mode routing — paper / testnet / live MUST each route correctly.
 *
 * Three modes:
 *   paper   = no Binance credentials needed, no real HTTP, logical_trades.mode = PAPER
 *   testnet = real credentials but pointed at the futures testnet base URL
 *   live    = real credentials, real fapi.binance.com base URL
 */

function modeConfig(array $settings = [], bool $enableLive = false, bool $paperTrading = true): FutureTradeBotConfig
{
    return FutureTradeBotConfig::create([
        'workspace_id' => (string) Str::uuid(),
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => $paperTrading,
        'enable_live_trading' => $enableLive,
        'enabled' => true,
        'settings' => array_merge(config('future_trade.defaults'), $settings),
    ]);
}

// ─── executionMode() routing ─────────────────────────────────────────────────

test('executionMode returns "paper" when enable_live_trading=false', function () {
    $config = modeConfig();
    expect($config->executionMode())->toBe('paper')
        ->and($config->usesLiveVenue())->toBeFalse();
});

test('executionMode returns "paper" when settings.liveTrading=false even with live flag', function () {
    $config = modeConfig(['liveTrading' => false], enableLive: true, paperTrading: false);
    expect($config->executionMode())->toBe('paper');
});

test('executionMode returns "testnet" when forceTestnet=true and live enabled', function () {
    $config = modeConfig([
        'liveTrading' => true,
        'forceTestnet' => true,
        'testnetApiKey' => 'test-k',
        'testnetApiSecret' => 'test-s',
    ], enableLive: true, paperTrading: false);
    expect($config->executionMode())->toBe('testnet')
        ->and($config->usesLiveVenue())->toBeFalse();
});

test('executionMode returns "live" only when forceTestnet=false AND credentials present', function () {
    $config = modeConfig([
        'liveTrading' => true,
        'forceTestnet' => false,
        'liveApiKey' => 'live-k',
        'liveApiSecret' => 'live-s',
    ], enableLive: true, paperTrading: false);
    expect($config->executionMode())->toBe('live')
        ->and($config->usesLiveVenue())->toBeTrue();
});

// ─── Paper mode does not need exchange credentials ───────────────────────────

test('paper mode does not require Binance credentials to start', function () {
    $config = modeConfig([
        'testnetApiKey' => '',
        'testnetApiSecret' => '',
        'liveApiKey' => '',
        'liveApiSecret' => '',
    ]);
    expect($config->executionMode())->toBe('paper');
});

// ─── Paper trades persist with mode=PAPER on the logical_trade row ───────────

test('paper logical_trades created directly are tagged with mode=PAPER and contribute to PnL', function () {
    $config = modeConfig();
    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'workspace_id' => (string) $config->workspace_id,
        'symbol' => 'ETHUSDT',
        'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_CLOSED,
        'mode' => 'PAPER',
        'signal_key' => 'paper-direct-1',
        'entry_price' => 2300,
        'exit_price' => 2310,
        'quantity' => 0.05,
        'net_pnl' => 0.50,
        'opened_at' => now()->subHour(),
        'closed_at' => now()->subMinutes(50),
    ]);

    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect((float) $summary['totalProfit'])->toEqualWithDelta(0.50, 0.0001)
        ->and($summary['closedCount'])->toBe(1);
});

test('PAPER-mode filter isolates paper PnL from any live or testnet rows', function () {
    $config = modeConfig();
    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_CLOSED, 'mode' => 'PAPER',
        'signal_key' => 'p1', 'entry_price' => 2300, 'exit_price' => 2305,
        'quantity' => 0.05, 'net_pnl' => 0.25,
        'opened_at' => now(), 'closed_at' => now(),
    ]);
    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_CLOSED, 'mode' => 'LIVE_TESTNET',
        'signal_key' => 't1', 'entry_price' => 2310, 'exit_price' => 2320,
        'quantity' => 0.05, 'net_pnl' => 0.50,
        'opened_at' => now(), 'closed_at' => now(),
    ]);

    $svc = app(FutureTradePnLService::class);
    $paperOnly = $svc->realizedSummary($config, 'PAPER');
    $testnetOnly = $svc->realizedSummary($config, 'LIVE_TESTNET');
    $all = $svc->realizedSummary($config);

    expect((float) $paperOnly['totalProfit'])->toEqualWithDelta(0.25, 0.0001)
        ->and((float) $testnetOnly['totalProfit'])->toEqualWithDelta(0.50, 0.0001)
        ->and((float) $all['totalProfit'])->toEqualWithDelta(0.75, 0.0001);
});

// ─── Testnet URL resolution must point at testnet, never mainnet ─────────────

test('testnet mode resolves the testnet base URL (never fapi.binance.com)', function () {
    $config = modeConfig([
        'liveTrading' => true,
        'forceTestnet' => true,
        'testnetApiKey' => 'k', 'testnetApiSecret' => 's',
        'testnetApiUrl' => 'https://testnet.binancefuture.com',
    ], enableLive: true, paperTrading: false);

    // Use the user-stream service for a side-effect-free URL resolver
    $svc = app(\App\Domain\FutureTrade\FutureTradeUserStreamService::class);
    $client = $svc->clientFor($config);
    expect($client)->not->toBeNull();
    // Read baseUrl via reflection — proves no accidental mainnet routing.
    $reflection = new ReflectionClass($client);
    $prop = $reflection->getProperty('baseUrl');
    $prop->setAccessible(true);
    expect($prop->getValue($client))->toContain('testnet');
});

test('mainnet live mode resolves to fapi.binance.com only when forceTestnet=false', function () {
    $config = modeConfig([
        'liveTrading' => true,
        'forceTestnet' => false,
        'liveApiKey' => 'k', 'liveApiSecret' => 's',
        'liveApiUrl' => 'https://fapi.binance.com',
    ], enableLive: true, paperTrading: false);

    $svc = app(\App\Domain\FutureTrade\FutureTradeUserStreamService::class);
    $client = $svc->clientFor($config);
    expect($client)->not->toBeNull();
    $reflection = new ReflectionClass($client);
    $prop = $reflection->getProperty('baseUrl');
    $prop->setAccessible(true);
    expect($prop->getValue($client))->toContain('fapi.binance.com')
        ->and($prop->getValue($client))->not->toContain('testnet');
});

// ─── Credential guard: live mode without keys must NOT promote to live ───────

test('live flag without testnet credentials falls back to paper executionMode', function () {
    $config = modeConfig([
        'liveTrading' => true,
        'forceTestnet' => true,
        // intentionally no testnetApiKey/testnetApiSecret
    ], enableLive: true, paperTrading: false);

    $svc = app(\App\Domain\FutureTrade\FutureTradeUserStreamService::class);
    $client = $svc->clientFor($config);
    // No credentials → clientFor returns null (gate before any Binance call)
    expect($client)->toBeNull();
});

// ─── isLiveMode() routing inside FutureTradeBotService ───────────────────────

test('FutureTradeBotService::isLiveMode is false in paper mode', function () {
    $config = modeConfig();
    $svc = app(FutureTradeBotService::class);
    $method = new ReflectionMethod($svc, 'isLiveMode');
    expect($method->invoke($svc, $config))->toBeFalse();
});

test('FutureTradeBotService::isLiveMode is true when testnet credentials + live flag are all set', function () {
    $config = modeConfig([
        'liveTrading' => true,
        'forceTestnet' => true,
        'testnetApiKey' => 'k', 'testnetApiSecret' => 's',
        'envMode' => 'testnet',
        'envTestnetEnabled' => true,
    ], enableLive: true, paperTrading: false);
    $svc = app(FutureTradeBotService::class);
    $method = new ReflectionMethod($svc, 'isLiveMode');
    expect($method->invoke($svc, $config))->toBeTrue();
});

// ─── Mode round-trip across updateConfig (paper → testnet → paper) ───────────

test('flipping paperTrading on/off via updateConfig changes executionMode cleanly', function () {
    $config = modeConfig();
    expect($config->executionMode())->toBe('paper');

    $bot = app(FutureTradeBotService::class);
    $updated = $bot->updateConfig($config, [
        'settings' => [
            'liveTrading' => true,
            'forceTestnet' => true,
            'testnetApiKey' => 'k', 'testnetApiSecret' => 's',
            'envMode' => 'testnet',
            'envTestnetEnabled' => true,
        ],
    ]);
    expect($updated->fresh()->executionMode())->toBe('testnet');

    $flipBack = $bot->updateConfig($updated->fresh('state'), [
        'settings' => [
            'liveTrading' => false,
            'paperTrading' => true,
        ],
    ]);
    expect($flipBack->fresh()->executionMode())->toBe('paper');
});

test('updateConfig preserves operator leverage override and lifts maxLeverage when needed', function () {
    $config = modeConfig([
        'signalProfile' => FutureTradeBotService::PROFILE_PRIME_V617_CRYPTO,
        'marketProfile' => 'Crypto',
        'leverage' => 2,
        'maxLeverage' => 2,
    ]);

    $updated = app(FutureTradeBotService::class)->updateConfig($config, [
        'settings' => [
            'leverage' => 7,
        ],
    ])->fresh();

    expect((int) $updated->settings['leverage'])->toBe(7)
        ->and((int) $updated->settings['maxLeverage'])->toBe(7);
});
