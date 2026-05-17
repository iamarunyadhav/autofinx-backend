<?php

use App\Domain\FutureTrade\FutureTradePnLService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Profit-and-loss prevention guards must be PROTECTIVE but SOFT — they must
 * faithfully count wins/losses without blocking valid trades and without
 * double-counting either side.
 */

function plsoftConfig(array $settings = []): FutureTradeBotConfig
{
    return FutureTradeBotConfig::create([
        'workspace_id' => (string) Str::uuid(),
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => true,
        'enable_live_trading' => false,
        'enabled' => true,
        'settings' => array_merge(config('future_trade.defaults'), $settings),
    ]);
}

function plsoftTrade(FutureTradeBotConfig $config, string $status, ?float $netPnl, array $attrs = []): FutureTradeLogicalTrade
{
    return FutureTradeLogicalTrade::create(array_merge([
        'future_trade_bot_config_id' => $config->id,
        'workspace_id' => (string) $config->workspace_id,
        'symbol' => 'ETHUSDT',
        'side' => 'LONG',
        'status' => $status,
        'mode' => 'PAPER',
        'signal_key' => 'sk-' . Str::uuid(),
        'entry_price' => 2300,
        'quantity' => 0.05,
        'net_pnl' => $netPnl,
        'opened_at' => now()->subMinutes(10),
        'closed_at' => $status === FutureTradeLogicalTrade::STATUS_CLOSED ? now()->subMinutes(5) : null,
    ], $attrs));
}

// ─── Block-the-losses guard tests ────────────────────────────────────────────

test('losses are counted accurately on the bot dashboard', function () {
    $config = plsoftConfig();
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, -1.40);
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, -0.85);

    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect((float) $summary['botProfit'])->toEqualWithDelta(0.0, 0.0001)
        ->and((float) $summary['botLoss'])->toEqualWithDelta(2.25, 0.0001)
        ->and((float) $summary['totalProfit'])->toEqualWithDelta(-2.25, 0.0001);
});

test('losses on different configs do not bleed into each other', function () {
    $configA = plsoftConfig();
    $configB = plsoftConfig();

    plsoftTrade($configA, FutureTradeLogicalTrade::STATUS_CLOSED, -2.5);
    plsoftTrade($configB, FutureTradeLogicalTrade::STATUS_CLOSED, +3.7);

    $a = app(FutureTradePnLService::class)->realizedSummary($configA);
    $b = app(FutureTradePnLService::class)->realizedSummary($configB);

    expect((float) $a['botLoss'])->toEqualWithDelta(2.5, 0.0001)
        ->and((float) $a['botProfit'])->toEqualWithDelta(0.0, 0.0001)
        ->and((float) $b['botProfit'])->toEqualWithDelta(3.7, 0.0001)
        ->and((float) $b['botLoss'])->toEqualWithDelta(0.0, 0.0001);
});

test('a loss row that is still OPEN is NEVER counted toward realized loss', function () {
    $config = plsoftConfig();
    // Position is sitting in a paper drawdown — net_pnl null while status=OPEN
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_OPEN, null);
    // A real closed loss alongside the open trade
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, -0.40);

    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect((float) $summary['botLoss'])->toEqualWithDelta(0.40, 0.0001)
        ->and($summary['openPositions'])->toBe(1);
});

test('CLOSING (in-flight exit) is treated as still-open, not as a finalised loss', function () {
    $config = plsoftConfig();
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSING, null);

    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect($summary['closedCount'])->toBe(0)
        ->and((float) $summary['botLoss'])->toEqualWithDelta(0.0, 0.0001)
        ->and($summary['openPositions'])->toBe(1);
});

test('CANCELLED entries (never filled) cannot generate phantom losses', function () {
    $config = plsoftConfig();
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CANCELLED, null);
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, +0.90);

    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect((float) $summary['botProfit'])->toEqualWithDelta(0.90, 0.0001)
        ->and((float) $summary['botLoss'])->toEqualWithDelta(0.0, 0.0001);
});

// ─── Allow-the-profits (soft) tests ───────────────────────────────────────────

test('profitable wins are summed exactly once per closed trade', function () {
    $config = plsoftConfig();
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, +1.25);
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, +0.65);
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, +0.40);

    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect($summary['closedCount'])->toBe(3)
        ->and((float) $summary['botProfit'])->toEqualWithDelta(2.30, 0.0001)
        ->and((float) $summary['totalProfit'])->toEqualWithDelta(2.30, 0.0001);
});

test('a profitable close cannot be cancelled out by a stale entry-row negative', function () {
    $config = plsoftConfig();
    // Closed profitable trade
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, +1.13, ['exit_price' => 2175]);
    // Simulate the historical stale-entry bug: an OPEN row with a "fake" negative
    // net_pnl that should NEVER be summed because status != CLOSED.
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_OPEN, -0.23, ['exit_price' => 2182.67]);

    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect((float) $summary['totalProfit'])->toEqualWithDelta(1.13, 0.0001)
        ->and((float) $summary['botLoss'])->toEqualWithDelta(0.0, 0.0001);
});

test('mixed wins and losses produce a net total that matches sum of CLOSED net_pnl', function () {
    $config = plsoftConfig();
    foreach ([+1.25, -0.50, +0.90, -0.20, +0.15, -1.10, +2.10] as $i => $pnl) {
        plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, $pnl, ['signal_key' => "mix-{$i}"]);
    }

    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect($summary['closedCount'])->toBe(7)
        ->and((float) $summary['totalProfit'])->toEqualWithDelta(+2.60, 0.0001);
});

// ─── Soft-edge tests (don't be too aggressive) ────────────────────────────────

test('a 0.00 net_pnl close still counts as a closed trade (no false-loss inflation)', function () {
    $config = plsoftConfig();
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, 0.0);

    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect($summary['closedCount'])->toBe(1)
        ->and((float) $summary['botProfit'])->toEqualWithDelta(0.0, 0.0001)
        ->and((float) $summary['botLoss'])->toEqualWithDelta(0.0, 0.0001);
});

test('a tiny profit (0.0001) is still recognised — guards are SOFT, not whole-cents', function () {
    $config = plsoftConfig();
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, 0.0001);

    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect((float) $summary['botProfit'])->toBeGreaterThan(0);
});

test('reconciliation-pending trades are surfaced but do NOT inflate either side', function () {
    $config = plsoftConfig();
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_RECONCILIATION_REQUIRED, null);
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, +1.00);

    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect($summary['closedCount'])->toBe(1)
        ->and((float) $summary['botProfit'])->toEqualWithDelta(1.0, 0.0001)
        ->and((float) $summary['botLoss'])->toEqualWithDelta(0.0, 0.0001)
        ->and($summary['reconciliationRequired'])->toBe(1);
});

test('empty config returns zeros — no NaN, no negative-zero, no false positives', function () {
    $config = plsoftConfig();
    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect($summary['closedCount'])->toBe(0)
        ->and((float) $summary['botProfit'])->toEqualWithDelta(0.0, 0.0001)
        ->and((float) $summary['botLoss'])->toEqualWithDelta(0.0, 0.0001)
        ->and((float) $summary['totalProfit'])->toEqualWithDelta(0.0, 0.0001)
        ->and($summary['openPositions'])->toBe(0);
});

test('large win streaks scale linearly without rounding drift', function () {
    $config = plsoftConfig();
    $expectedTotal = 0.0;
    for ($i = 0; $i < 25; $i++) {
        $pnl = 0.10 + ($i * 0.013);
        $expectedTotal += $pnl;
        plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, $pnl, ['signal_key' => "streak-{$i}"]);
    }

    $summary = app(FutureTradePnLService::class)->realizedSummary($config);
    expect((float) $summary['totalProfit'])->toEqualWithDelta($expectedTotal, 0.001);
});

test('mode filter isolates LIVE-mode and PAPER-mode PnL when requested', function () {
    $config = plsoftConfig();
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, +1.10, ['mode' => 'LIVE_TESTNET', 'signal_key' => 'mode-1']);
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, -0.30, ['mode' => 'PAPER', 'signal_key' => 'mode-2']);
    plsoftTrade($config, FutureTradeLogicalTrade::STATUS_CLOSED, +0.40, ['mode' => 'PAPER', 'signal_key' => 'mode-3']);

    $svc = app(FutureTradePnLService::class);
    $paperOnly = $svc->realizedSummary($config, 'PAPER');
    $liveOnly = $svc->realizedSummary($config, 'LIVE_TESTNET');

    expect((float) $paperOnly['totalProfit'])->toEqualWithDelta(0.10, 0.0001)
        ->and((float) $liveOnly['totalProfit'])->toEqualWithDelta(1.10, 0.0001);
});
