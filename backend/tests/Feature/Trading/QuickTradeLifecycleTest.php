<?php

use App\Models\LedgerTransaction;
use App\Models\MarketTick;
use App\Models\QuickTrade;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('manual quick trade settles on expiry and credits wallet and ledger', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $this->actingAs($user);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 10:00:00'));

    $response = $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'GOLD_OTC',
        'side' => 'buy',
        'stake_amount' => 10,
        'timeframe' => '1m',
        'expiry_seconds' => 5,
        'mode' => 'paper',
        'indicator_set' => ['RSI', 'MACD'],
    ])->assertCreated();

    $quickTrade = QuickTrade::findOrFail($response->json('id'));
    $wallet = WalletAccount::findOrFail($quickTrade->wallet_account_id);
    expect((float) $wallet->balance)->toBe(9990.0);

    $symbol = Symbol::where('symbol', 'GOLD_OTC')->firstOrFail();
    MarketTick::create([
        'id' => (string) Str::uuid(),
        'symbol_id' => $symbol->id,
        'price' => (float) $quickTrade->entry_price + 2,
        'bid' => (float) $quickTrade->entry_price + 1.9,
        'ask' => (float) $quickTrade->entry_price + 2.1,
        'volume' => 50,
        'occurred_at' => CarbonImmutable::parse('2026-03-10 10:00:06'),
        'metadata' => ['test' => true],
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 10:00:07'));

    $this->postJson('/api/v1/quick-trade/settle-due', ['limit' => 10])->assertOk();

    $quickTrade->refresh();
    $wallet->refresh();

    expect($quickTrade->status)->toBe('won')
        ->and((float) $quickTrade->profit_loss)->toBeGreaterThan(0)
        ->and((float) $wallet->balance)->toBeGreaterThan(10000.0)
        ->and(Trade::count())->toBe(1)
        ->and((float) Trade::first()->realized_pnl)->toBeGreaterThan(0)
        ->and(LedgerTransaction::count())->toBe(2);

    CarbonImmutable::setTestNow();
});
