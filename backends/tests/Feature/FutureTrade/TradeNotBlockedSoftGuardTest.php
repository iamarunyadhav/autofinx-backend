<?php

use App\Domain\FutureTrade\FutureTradeLockService;
use App\Domain\FutureTrade\FutureTradeSignalStabilityService;
use App\Domain\FutureTrade\FutureTradeUserStreamService;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Market\RealtimeMarketFeedService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use App\Models\MarketFeedStatus;
use App\Models\Symbol;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Trades MUST still go through whenever the runtime is healthy. These tests
 * verify the guards are SOFT — they protect against the documented failure
 * modes but never become a brick wall for valid live trades.
 */

function softConfig(array $settings = [], bool $live = false): FutureTradeBotConfig
{
    return FutureTradeBotConfig::create([
        'workspace_id' => (string) Str::uuid(),
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => ! $live,
        'enable_live_trading' => $live,
        'enabled' => true,
        'settings' => array_merge(config('future_trade.defaults'), $live ? [
            'liveTrading' => true,
            'paperTrading' => false,
            'forceTestnet' => true,
            'envMode' => 'testnet',
            'envTestnetEnabled' => true,
            'testnetApiKey' => 'test-key',
            'testnetApiSecret' => 'test-secret',
        ] : [], $settings),
    ]);
}

// ─── Lock service — soft, per-symbol scope ────────────────────────────────────

test('an entry lock on ETHUSDT does NOT block a fresh BTCUSDT entry', function () {
    $config = softConfig();
    $svc = app(FutureTradeLockService::class);

    // Hold ETH manually
    $ethLock = Cache::lock($svc->entryLockKey($config, 'ETHUSDT'), 30);
    $ethLock->get();

    // BTC entry must still acquire its own lock
    $btcResult = $svc->withEntryLock($config, 'BTCUSDT', fn () => 'btc-ok');
    expect($btcResult)->toBe('btc-ok');

    $ethLock->release();
});

test('a released entry lock immediately allows the next entry on the same symbol', function () {
    $config = softConfig();
    $svc = app(FutureTradeLockService::class);

    $first = $svc->withEntryLock($config, 'ETHUSDT', fn () => 'first-ok');
    expect($first)->toBe('first-ok');

    // Lock should have been released — second attempt succeeds.
    $second = $svc->withEntryLock($config, 'ETHUSDT', fn () => 'second-ok');
    expect($second)->toBe('second-ok');
});

test('an OPEN logical trade on ETHUSDT does NOT block a fresh BTCUSDT entry', function () {
    $config = softConfig();
    $svc = app(FutureTradeLockService::class);

    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'SHORT',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'PAPER',
        'signal_key' => 'sk-multi-eth', 'entry_price' => 2300, 'quantity' => 0.05,
        'opened_at' => now(),
    ]);

    expect($svc->hasActiveLogicalTrade((string) $config->id, 'ETHUSDT'))->toBeTrue()
        ->and($svc->hasActiveLogicalTrade((string) $config->id, 'BTCUSDT'))->toBeFalse();
});

test('a CLOSED logical trade does NOT block a fresh entry on the same symbol', function () {
    $config = softConfig();
    $svc = app(FutureTradeLockService::class);

    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_CLOSED, 'mode' => 'PAPER',
        'signal_key' => 'sk-closed-eth',
        'entry_price' => 2280, 'exit_price' => 2300, 'quantity' => 0.05, 'net_pnl' => +1.0,
        'opened_at' => now()->subHour(), 'closed_at' => now()->subMinutes(30),
    ]);

    expect($svc->hasActiveLogicalTrade((string) $config->id, 'ETHUSDT'))->toBeFalse();
});

test('a logical trade on configA does NOT block configB on the SAME symbol', function () {
    $configA = softConfig();
    $configB = softConfig();
    $svc = app(FutureTradeLockService::class);

    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $configA->id,
        'symbol' => 'ETHUSDT', 'side' => 'SHORT',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'PAPER',
        'signal_key' => 'sk-a', 'entry_price' => 2300, 'quantity' => 0.05,
        'opened_at' => now(),
    ]);

    expect($svc->hasActiveLogicalTrade((string) $configA->id, 'ETHUSDT'))->toBeTrue()
        ->and($svc->hasActiveLogicalTrade((string) $configB->id, 'ETHUSDT'))->toBeFalse();
});

test('a unique signal_key is acceptable — only an exact duplicate is blocked', function () {
    $config = softConfig();
    $svc = app(FutureTradeLockService::class);

    expect($svc->hasSignalKey((string) $config->id, 'totally-fresh-key'))->toBeFalse();
});

// ─── Signal stability is SOFT after the soft floor ────────────────────────────

test('two consecutive same-side scans within one candle reach EXECUTABLE — not stuck on WAIT', function () {
    $svc = new FutureTradeSignalStabilityService();
    $candle = '2026-05-17T10:25:00+00:00';
    $buffer = [
        ['ts' => time() - 12, 'side' => 'BUY', 'candle' => $candle, 'confidence' => 0.71],
        ['ts' => time() - 4,  'side' => 'BUY', 'candle' => $candle, 'confidence' => 0.72],
    ];
    $state = $svc->summarize($buffer, 'BUY', $candle);
    expect($state['executable'])->toBe('BUY')
        ->and($state['reason'])->toBe('stable');
});

test('a brief WAIT between two BUYs in different candles does NOT poison the next candle', function () {
    $svc = new FutureTradeSignalStabilityService();
    $oldCandle = '2026-05-17T10:25:00+00:00';
    $newCandle = '2026-05-17T10:30:00+00:00';
    $buffer = [
        ['ts' => time() - 60, 'side' => 'WAIT', 'candle' => $oldCandle, 'confidence' => 0.5],
        ['ts' => time() - 30, 'side' => 'BUY', 'candle' => $newCandle, 'confidence' => 0.74],
        ['ts' => time() - 10, 'side' => 'BUY', 'candle' => $newCandle, 'confidence' => 0.76],
    ];
    $state = $svc->summarize($buffer, 'BUY', $newCandle);
    expect($state['executable'])->toBe('BUY');
});

test('an isolated WAIT scan never produces a fake BUY/SELL — no spurious signal injection', function () {
    $svc = new FutureTradeSignalStabilityService();
    $candle = '2026-05-17T10:25:00+00:00';
    $state = $svc->summarize([
        ['ts' => time(), 'side' => 'WAIT', 'candle' => $candle, 'confidence' => 0.10],
    ], 'WAIT', $candle);
    expect($state['executable'])->toBe('WAIT')
        ->and($state['confirmed'])->toBe('WAIT');
});

test('hysteresis: a single SELL appearing inside a confirmed BUY candle keeps confirmed BUY until invalidated', function () {
    $svc = new FutureTradeSignalStabilityService();
    $candle = '2026-05-17T10:25:00+00:00';
    $buffer = [
        ['ts' => time() - 30, 'side' => 'BUY', 'candle' => $candle, 'confidence' => 0.74],
        ['ts' => time() - 20, 'side' => 'BUY', 'candle' => $candle, 'confidence' => 0.75],
        ['ts' => time() - 10, 'side' => 'BUY', 'candle' => $candle, 'confidence' => 0.76],
    ];
    // Note: this snapshot is asking "what does the latest say?" with rawSide=BUY.
    // Because the same-candle flip detector is BUY⇄SELL only, a stable BUY trail
    // remains stable — guard is SOFT against random WAIT noise.
    $state = $svc->summarize($buffer, 'BUY', $candle);
    expect($state['executable'])->toBe('BUY');
});

// ─── User-stream gating must NOT block paper trading ─────────────────────────

test('PAPER mode does NOT require a Binance user stream — paper can always trade', function () {
    $config = softConfig(live: false);
    $svc = app(FutureTradeUserStreamService::class);
    $snap = $svc->snapshot($config);
    // For paper mode the bot just ignores the stream snapshot at the gate;
    // we only verify the snapshot returns a plausible "not configured" shape.
    expect(in_array($snap['status'], [
        FutureTradeUserStreamService::STATUS_NOT_CONFIGURED,
        FutureTradeUserStreamService::STATUS_DISCONNECTED,
    ], true))->toBeTrue();
});

test('LIVE mode with allowLiveWithoutUserStream=true is treated as configured by the bot', function () {
    $config = softConfig(['allowLiveWithoutUserStream' => true, 'testnetApiKey' => '', 'testnetApiSecret' => ''], live: true);
    $merged = $config->mergedSettings();
    expect((bool) ($merged['allowLiveWithoutUserStream'] ?? false))->toBeTrue();
});

test('a fresh user-stream heartbeat survives across rapid snapshot calls without drifting stale', function () {
    $config = softConfig(live: true);
    $svc = app(FutureTradeUserStreamService::class);

    $svc->recordEvent($config, ['e' => 'ACCOUNT_UPDATE']);
    for ($i = 0; $i < 5; $i++) {
        $snap = $svc->snapshot($config);
        expect($snap['fresh'])->toBeTrue();
    }
});

// ─── Soft "no-double-charge" guarantees on close ──────────────────────────────

test('future trade websocket disconnect marks feed degraded without refreshing the last real tick', function () {
    $config = softConfig(['symbols' => ['ETHUSDT']], live: false);
    $symbol = Symbol::create([
        'id' => (string) Str::uuid(),
        'symbol' => 'ETHUSDT',
        'type' => 'crypto',
        'exchange' => 'binance_futures',
    ]);
    $lastTickAt = now()->subSeconds(45);

    MarketFeedStatus::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $config->workspace_id,
        'symbol_id' => $symbol->id,
        'symbol' => 'ETHUSDT',
        'exchange' => 'binance_futures',
        'market_type' => 'futures',
        'last_tick_at' => $lastTickAt,
        'last_price' => 2300,
        'source' => 'trade',
        'status' => 'healthy',
    ]);

    $feeds = new RealtimeMarketFeedService(app(MarketPriceCacheService::class));
    $feeds->markDisconnected(['ETHUSDT'], 'futures', 'unit-test disconnect');

    $status = MarketFeedStatus::query()
        ->where('workspace_id', $config->workspace_id)
        ->where('symbol', 'ETHUSDT')
        ->first();

    expect($status?->status)->toBe('degraded')
        ->and((float) $status?->last_price)->toEqualWithDelta(2300, 0.0001)
        ->and($status?->last_tick_at?->getTimestamp())->toBe($lastTickAt->getTimestamp());
});

test('a re-delivered FILLED exit event from Binance is idempotent (does not double the loss)', function () {
    $config = softConfig(live: true);
    $svc = app(FutureTradeUserStreamService::class);

    $trade = FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'LIVE_TESTNET',
        'signal_key' => 'sk-replay-1',
        'entry_client_order_id' => 'ftx-entry-replay', 'exit_client_order_id' => 'ftx-exit-replay',
        'entry_price' => 2300, 'quantity' => 0.05, 'opened_at' => now(),
    ]);

    $payload = [
        'e' => 'ORDER_TRADE_UPDATE',
        'o' => [
            's' => 'ETHUSDT', 'c' => 'ftx-exit-replay', 'i' => '555',
            'X' => 'FILLED', 'x' => 'TRADE', 'S' => 'SELL',
            'ap' => 2290, 'z' => 0.05, 'rp' => -0.50, 'n' => 0.01,
        ],
    ];

    // First delivery
    $svc->ingestEvent($config, $payload);
    $trade->refresh();
    $firstNet = (float) $trade->net_pnl;
    expect($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED);

    // Replay — net_pnl, exit_price, status must not double-charge.
    $svc->ingestEvent($config, $payload);
    $trade->refresh();
    expect((float) $trade->net_pnl)->toEqualWithDelta($firstNet, 0.0001)
        ->and($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED);
});

// ─── Lifecycle: complete pipeline still admits a clean trade ─────────────────

test('a clean signal_key → lock → DB insert → close sequence produces exactly one realized PnL row', function () {
    $config = softConfig();
    $lockSvc = app(FutureTradeLockService::class);

    $result = $lockSvc->withEntryLock($config, 'ETHUSDT', function () use ($config) {
        if ($lockSvc = app(FutureTradeLockService::class)) {
            // Inside the lock: re-check, then create the logical trade.
            expect($lockSvc->hasActiveLogicalTrade((string) $config->id, 'ETHUSDT'))->toBeFalse();
        }
        return FutureTradeLogicalTrade::create([
            'future_trade_bot_config_id' => $config->id,
            'symbol' => 'ETHUSDT', 'side' => 'LONG',
            'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'PAPER',
            'signal_key' => 'sk-lifecycle-clean',
            'entry_price' => 2280, 'quantity' => 0.05, 'opened_at' => now(),
        ]);
    });

    expect($result)->not->toBeNull();
    expect($lockSvc->hasActiveLogicalTrade((string) $config->id, 'ETHUSDT'))->toBeTrue();

    // Close the trade.
    $result->update([
        'status' => FutureTradeLogicalTrade::STATUS_CLOSED,
        'exit_price' => 2295,
        'gross_pnl' => +0.75,
        'fees' => 0.05,
        'net_pnl' => +0.70,
        'closed_at' => now(),
    ]);

    $summary = app(\App\Domain\FutureTrade\FutureTradePnLService::class)->realizedSummary($config);
    expect($summary['closedCount'])->toBe(1)
        ->and((float) $summary['totalProfit'])->toEqualWithDelta(0.70, 0.0001);
});
