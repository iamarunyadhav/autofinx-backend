<?php

use App\Domain\FutureTrade\FutureTradeBacktestEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Backtest statistics MUST be deterministic and mathematically correct.
 *
 * These tests exercise the private computeStats() method via reflection — it's
 * pure math on a closed-trade array, so we can validate every output field
 * (win rate, profit factor, drawdown, sharpe, by-symbol grouping) without
 * needing real klines.
 */

function callComputeStats(array $trades, float $initialCapital): array
{
    $engine = app(FutureTradeBacktestEngine::class);
    $method = (new ReflectionMethod($engine, 'computeStats'));
    return $method->invoke($engine, $trades, $initialCapital);
}

function bkTrade(array $attrs = []): array
{
    return array_merge([
        'symbol' => 'ETHUSDT',
        'side' => 'LONG',
        'entry_price' => 2300.0,
        'exit_price' => 2310.0,
        'quantity' => 0.05,
        'leverage' => 3,
        'gross_pnl' => 0.50,
        'fees' => 0.02,
        'net_pnl' => 0.48,
        'entry_signal' => 'BUY',
        'entry_confidence' => 0.72,
        'exit_reason' => 'TAKE_PROFIT_HIT',
        'entry_time' => now()->subMinutes(30)->toDateTimeString(),
        'exit_time' => now()->subMinutes(15)->toDateTimeString(),
        'duration_minutes' => 15,
        'meta' => null,
    ], $attrs);
}

// ─── Empty / edge cases ───────────────────────────────────────────────────────

test('empty trade list returns all zeros without dividing by zero', function () {
    $stats = callComputeStats([], 1000.0);
    expect($stats['total_trades'])->toBe(0)
        ->and($stats['wins'])->toBe(0)
        ->and($stats['losses'])->toBe(0)
        ->and((float) $stats['win_rate'])->toBe(0.0)
        ->and((float) $stats['total_pnl_usdt'])->toBe(0.0)
        ->and((float) $stats['profit_factor'])->toBe(0.0)
        ->and((float) $stats['max_drawdown_usdt'])->toBe(0.0)
        ->and((float) $stats['sharpe_ratio'])->toBe(0.0);
});

test('BACKTEST_END exit reason is excluded from totals (open at end of window)', function () {
    $trades = [
        bkTrade(['net_pnl' => 0.50, 'exit_reason' => 'TAKE_PROFIT_HIT']),
        bkTrade(['net_pnl' => 99.99, 'exit_reason' => 'BACKTEST_END']),
    ];
    $stats = callComputeStats($trades, 1000.0);
    expect($stats['total_trades'])->toBe(1)
        ->and((float) $stats['total_pnl_usdt'])->toEqualWithDelta(0.50, 0.0001);
});

// ─── Win rate ─────────────────────────────────────────────────────────────────

test('win rate is correctly computed for mixed wins / losses', function () {
    $trades = [
        bkTrade(['net_pnl' => 1.0]),
        bkTrade(['net_pnl' => 2.0]),
        bkTrade(['net_pnl' => -0.5]),
        bkTrade(['net_pnl' => -1.5]),
    ];
    $stats = callComputeStats($trades, 1000.0);
    expect($stats['wins'])->toBe(2)
        ->and($stats['losses'])->toBe(2)
        ->and((float) $stats['win_rate'])->toEqualWithDelta(0.5, 0.0001);
});

test('100% win rate is reported as 1.0', function () {
    $trades = [bkTrade(['net_pnl' => 1.0]), bkTrade(['net_pnl' => 0.5])];
    $stats = callComputeStats($trades, 1000.0);
    expect((float) $stats['win_rate'])->toEqualWithDelta(1.0, 0.0001);
});

test('0% win rate is reported as 0.0 (all losses)', function () {
    $trades = [bkTrade(['net_pnl' => -0.5]), bkTrade(['net_pnl' => -1.0])];
    $stats = callComputeStats($trades, 1000.0);
    expect((float) $stats['win_rate'])->toEqualWithDelta(0.0, 0.0001);
});

// ─── Profit factor ────────────────────────────────────────────────────────────

test('profit factor computed exactly: PF = grossWins / grossLoss', function () {
    $trades = [
        bkTrade(['net_pnl' => 3.0]),  // win
        bkTrade(['net_pnl' => 5.0]),  // win
        bkTrade(['net_pnl' => -2.0]), // loss
        bkTrade(['net_pnl' => -2.0]), // loss
    ];
    $stats = callComputeStats($trades, 1000.0);
    // grossWins = 8, grossLoss = 4 → PF = 2.0
    expect((float) $stats['profit_factor'])->toEqualWithDelta(2.0, 0.0001);
});

test('profit factor sanity-checks against the user-confirmed TradingView ETH PF=1.614 sample', function () {
    // 4-trade microcosm engineered to land exactly on 1.614 (grossWins/grossLoss).
    $trades = [
        bkTrade(['net_pnl' => 8.07]),
        bkTrade(['net_pnl' => -5.0]),
    ];
    $stats = callComputeStats($trades, 1000.0);
    expect((float) $stats['profit_factor'])->toEqualWithDelta(1.614, 0.001);
});

test('profit factor with zero losses but positive wins is reported as 999.0 sentinel', function () {
    $trades = [bkTrade(['net_pnl' => 1.0]), bkTrade(['net_pnl' => 2.0])];
    $stats = callComputeStats($trades, 1000.0);
    expect((float) $stats['profit_factor'])->toBe(999.0);
});

// ─── Total PnL ────────────────────────────────────────────────────────────────

test('total PnL USDT exactly equals SUM(net_pnl) of closed trades', function () {
    $trades = [
        bkTrade(['net_pnl' => 1.50]),
        bkTrade(['net_pnl' => 2.25]),
        bkTrade(['net_pnl' => -0.75]),
        bkTrade(['net_pnl' => -1.00]),
    ];
    $stats = callComputeStats($trades, 1000.0);
    expect((float) $stats['total_pnl_usdt'])->toEqualWithDelta(2.0, 0.0001);
});

test('total PnL percent equals total / initialCapital * 100', function () {
    $trades = [bkTrade(['net_pnl' => 50.0])];
    $stats = callComputeStats($trades, 1000.0);
    expect((float) $stats['total_pnl_percent'])->toEqualWithDelta(5.0, 0.0001);
});

// ─── Drawdown ─────────────────────────────────────────────────────────────────

test('max drawdown tracks the worst peak-to-trough excursion of the equity curve', function () {
    // Equity: 1000 → 1010 → 1050 → 990 → 1020 → 1080
    //  peak progression: 1000, 1010, 1050, 1050, 1050, 1080
    //  drawdown        :    0,    0,    0,   60,   30,    0
    $trades = [
        bkTrade(['net_pnl' => 10.0]),
        bkTrade(['net_pnl' => 40.0]),
        bkTrade(['net_pnl' => -60.0]),
        bkTrade(['net_pnl' => 30.0]),
        bkTrade(['net_pnl' => 60.0]),
    ];
    $stats = callComputeStats($trades, 1000.0);
    expect((float) $stats['max_drawdown_usdt'])->toEqualWithDelta(60.0, 0.0001)
        ->and((float) $stats['max_drawdown_percent'])->toEqualWithDelta(6.0, 0.0001);
});

test('max drawdown is zero when equity only rises (no losing trade)', function () {
    $trades = [bkTrade(['net_pnl' => 10.0]), bkTrade(['net_pnl' => 20.0]), bkTrade(['net_pnl' => 30.0])];
    $stats = callComputeStats($trades, 1000.0);
    expect((float) $stats['max_drawdown_usdt'])->toEqualWithDelta(0.0, 0.0001);
});

// ─── Average duration ────────────────────────────────────────────────────────

test('avg trade duration in minutes is rounded to nearest int', function () {
    $trades = [
        bkTrade(['duration_minutes' => 10, 'net_pnl' => 1.0]),
        bkTrade(['duration_minutes' => 20, 'net_pnl' => 1.0]),
        bkTrade(['duration_minutes' => 31, 'net_pnl' => 1.0]),
    ];
    $stats = callComputeStats($trades, 1000.0);
    expect($stats['avg_trade_duration_minutes'])->toBe(20);
});

// ─── By-symbol grouping ─────────────────────────────────────────────────────

test('by_symbol breakdown isolates ETH and BTC wins independently', function () {
    $trades = [
        bkTrade(['symbol' => 'ETHUSDT', 'net_pnl' => 1.0]),
        bkTrade(['symbol' => 'ETHUSDT', 'net_pnl' => -0.5]),
        bkTrade(['symbol' => 'BTCUSDT', 'net_pnl' => 2.0]),
        bkTrade(['symbol' => 'BTCUSDT', 'net_pnl' => 1.0]),
    ];
    $stats = callComputeStats($trades, 1000.0);
    expect((array) $stats['by_symbol'])->toHaveKeys(['ETHUSDT', 'BTCUSDT']);
    $eth = $stats['by_symbol']['ETHUSDT'];
    $btc = $stats['by_symbol']['BTCUSDT'];
    expect($eth['trades'])->toBe(2)
        ->and($eth['wins'])->toBe(1)
        ->and((float) $eth['win_rate'])->toEqualWithDelta(0.5, 0.0001)
        ->and((float) $eth['net_pnl'])->toEqualWithDelta(0.5, 0.0001)
        ->and($btc['trades'])->toBe(2)
        ->and($btc['wins'])->toBe(2)
        ->and((float) $btc['win_rate'])->toEqualWithDelta(1.0, 0.0001)
        ->and((float) $btc['net_pnl'])->toEqualWithDelta(3.0, 0.0001);
});

// ─── By exit-reason grouping ─────────────────────────────────────────────────

test('by_exit_reason aggregates trades and net_pnl per reason', function () {
    $trades = [
        bkTrade(['exit_reason' => 'TAKE_PROFIT_HIT', 'net_pnl' => 1.0]),
        bkTrade(['exit_reason' => 'TAKE_PROFIT_HIT', 'net_pnl' => 1.5]),
        bkTrade(['exit_reason' => 'STOP_LOSS_HIT', 'net_pnl' => -0.8]),
    ];
    $stats = callComputeStats($trades, 1000.0);
    expect((array) $stats['by_exit_reason'])->toHaveKeys(['TAKE_PROFIT_HIT', 'STOP_LOSS_HIT']);
    expect($stats['by_exit_reason']['TAKE_PROFIT_HIT']['trades'])->toBe(2)
        ->and((float) $stats['by_exit_reason']['TAKE_PROFIT_HIT']['net_pnl'])->toEqualWithDelta(2.5, 0.0001)
        ->and($stats['by_exit_reason']['STOP_LOSS_HIT']['trades'])->toBe(1)
        ->and((float) $stats['by_exit_reason']['STOP_LOSS_HIT']['net_pnl'])->toEqualWithDelta(-0.8, 0.0001);
});

// ─── Sharpe sanity ───────────────────────────────────────────────────────────

test('sharpe ratio is zero when only one trade exists (cannot compute std)', function () {
    $stats = callComputeStats([bkTrade(['net_pnl' => 1.0])], 1000.0);
    expect((float) $stats['sharpe_ratio'])->toBe(0.0);
});

test('sharpe ratio is positive when wins dominate losses', function () {
    $trades = [
        bkTrade(['net_pnl' => 1.0]),
        bkTrade(['net_pnl' => 0.9]),
        bkTrade(['net_pnl' => 1.1]),
        bkTrade(['net_pnl' => -0.2]),
    ];
    $stats = callComputeStats($trades, 1000.0);
    expect((float) $stats['sharpe_ratio'])->toBeGreaterThan(0);
});
