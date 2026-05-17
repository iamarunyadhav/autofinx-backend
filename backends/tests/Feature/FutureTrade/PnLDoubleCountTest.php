<?php

use App\Domain\FutureTrade\FutureTradePnLService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function ftMakeConfig(): FutureTradeBotConfig
{
    return FutureTradeBotConfig::create([
        'workspace_id' => (string) \Illuminate\Support\Str::uuid(),
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => true,
        'enable_live_trading' => false,
        'enabled' => true,
        'settings' => config('future_trade.defaults'),
    ]);
}

test('realized PnL counts only CLOSED logical trades', function () {
    $config = ftMakeConfig();
    $svc = app(FutureTradePnLService::class);

    // 1 closed win, 1 closed loss, 1 still open — open MUST be ignored.
    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT',
        'side' => 'SHORT',
        'status' => FutureTradeLogicalTrade::STATUS_CLOSED,
        'mode' => 'PAPER',
        'signal_key' => 'sk-1',
        'entry_price' => 2300, 'exit_price' => 2275,
        'quantity' => 0.05, 'net_pnl' => 1.25,
        'opened_at' => now()->subMinutes(10), 'closed_at' => now()->subMinutes(5),
    ]);
    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'BTCUSDT',
        'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_CLOSED,
        'mode' => 'PAPER',
        'signal_key' => 'sk-2',
        'entry_price' => 65000, 'exit_price' => 64800,
        'quantity' => 0.001, 'net_pnl' => -0.20,
        'opened_at' => now()->subMinutes(8), 'closed_at' => now()->subMinutes(3),
    ]);
    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT',
        'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN,
        'mode' => 'PAPER',
        'signal_key' => 'sk-3',
        'entry_price' => 2400, 'quantity' => 0.05, 'net_pnl' => null,
        'opened_at' => now()->subMinute(),
    ]);

    $summary = $svc->realizedSummary($config);
    expect($summary['closedCount'])->toBe(2)
        ->and((float) $summary['botProfit'])->toEqualWithDelta(1.25, 0.0001)
        ->and((float) $summary['botLoss'])->toEqualWithDelta(0.20, 0.0001)
        ->and((float) $summary['totalProfit'])->toEqualWithDelta(1.05, 0.0001)
        ->and($summary['openPositions'])->toBe(1);
});

test('signal_key uniqueness blocks the same signal from creating two logical trades', function () {
    $config = ftMakeConfig();

    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT',
        'side' => 'SHORT',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN,
        'mode' => 'PAPER',
        'signal_key' => 'collide-key',
        'entry_price' => 2300, 'quantity' => 0.05,
        'opened_at' => now(),
    ]);

    $thrown = false;
    try {
        FutureTradeLogicalTrade::create([
            'future_trade_bot_config_id' => $config->id,
            'symbol' => 'ETHUSDT',
            'side' => 'SHORT',
            'status' => FutureTradeLogicalTrade::STATUS_OPEN,
            'mode' => 'PAPER',
            'signal_key' => 'collide-key', // same key
            'entry_price' => 2301, 'quantity' => 0.05,
            'opened_at' => now(),
        ]);
    } catch (\Throwable $e) {
        $thrown = true;
    }
    expect($thrown)->toBeTrue();
});
