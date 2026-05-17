<?php

use App\Domain\FutureTrade\FutureTradeLockService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function lockTestConfig(): FutureTradeBotConfig
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

test('hasActiveLogicalTrade is true once an OPEN logical trade exists', function () {
    $config = lockTestConfig();
    $svc = app(FutureTradeLockService::class);
    expect($svc->hasActiveLogicalTrade((string) $config->id, 'ETHUSDT'))->toBeFalse();

    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'SHORT',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN,
        'mode' => 'PAPER',
        'entry_price' => 2300, 'quantity' => 0.05,
        'signal_key' => 'lock-sk-1',
        'opened_at' => now(),
    ]);
    expect($svc->hasActiveLogicalTrade((string) $config->id, 'ETHUSDT'))->toBeTrue();
});

test('withEntryLock returns null when a second caller cannot acquire the lock', function () {
    $config = lockTestConfig();
    $svc = app(FutureTradeLockService::class);

    // Manually acquire the lock so the inner call must fail.
    $lock = Cache::lock($svc->entryLockKey($config, 'ETHUSDT'), 30);
    $lock->get();

    $result = $svc->withEntryLock($config, 'ETHUSDT', function () {
        return 'should-not-run';
    });

    expect($result)->toBeNull();
    $lock->release();
});

test('hasSignalKey detects an existing signal_key per config', function () {
    $config = lockTestConfig();
    $svc = app(FutureTradeLockService::class);
    expect($svc->hasSignalKey((string) $config->id, 'unique-key'))->toBeFalse();

    FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id,
        'symbol' => 'ETHUSDT', 'side' => 'SHORT',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN,
        'mode' => 'PAPER',
        'entry_price' => 2300, 'quantity' => 0.05,
        'signal_key' => 'unique-key',
        'opened_at' => now(),
    ]);
    expect($svc->hasSignalKey((string) $config->id, 'unique-key'))->toBeTrue();
});
