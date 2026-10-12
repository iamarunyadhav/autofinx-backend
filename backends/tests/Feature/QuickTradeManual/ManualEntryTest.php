<?php

use App\Domain\QuickTradeManual\ManualFeatureGate;
use App\Models\QuickTrade;
use App\Models\QuickTradeManualEvent;
use App\Models\QuickTradeManualPosition as P;
use App\Models\WalletAccount;
use App\Models\User;
use Tests\Support\ManualTradeScenario;

function qtmOpen(ManualTradeScenario $s, array $over = []): \Illuminate\Testing\TestResponse
{
    test()->actingAs($s->user);

    return test()->postJson($s->url('/positions'), $s->payload($over));
}

function qtmEvents(P $p): array
{
    return QuickTradeManualEvent::query()->where('position_id', $p->id)->orderBy('seq')->get()->map(fn ($e) => $e->type)->all();
}

test('flags default OFF: nothing works until BOTH the server flag and the workspace flag are on', function () {
    $s = ManualTradeScenario::make('testnet', false);
    $this->actingAs($s->user);

    expect(config('quick_trade_manual.enabled'))->toBeFalse();
    $this->postJson($s->url('/preview'), $s->payload())->assertStatus(403)->assertJsonPath('code', 'feature_disabled');
    $this->postJson($s->url('/positions'), $s->payload())->assertStatus(403)->assertJsonPath('code', 'feature_disabled');

    // server flag on, workspace flag still off
    config()->set('quick_trade_manual.enabled', true);
    $this->postJson($s->url('/positions'), $s->payload())->assertStatus(403)->assertJsonPath('code', 'feature_disabled');
    expect(P::count())->toBe(0)->and($s->fake->calls)->toBe([]);

    // workspace flag on, server flag off
    app(ManualFeatureGate::class)->setWorkspaceEnabled($s->workspace->id, true);
    config()->set('quick_trade_manual.enabled', false);
    $this->postJson($s->url('/positions'), $s->payload())->assertStatus(403);
    expect($s->fake->calls)->toBe([]);
});

test('config default for the server flag is false', function () {
    $cfg = require base_path('config/quick_trade_manual.php');
    expect($cfg['enabled'])->toBeFalse()->and($cfg['allow_mainnet'])->toBeFalse();
});

test('mainnet is blocked until explicitly allowed', function () {
    $s = ManualTradeScenario::make('live');
    qtmOpen($s)->assertStatus(403)->assertJsonPath('code', 'mainnet_blocked');
    expect($s->fake->calls)->toBe([]);

    config()->set('quick_trade_manual.allow_mainnet', true);
    $s->fake->env = 'live';
    qtmOpen($s)->assertCreated()->assertJsonPath('status', 'OPEN_PROTECTED');
});

test('only owner/admin can switch the workspace flag, and not on when the server flag is off', function () {
    $s = ManualTradeScenario::make('testnet', true, 'member');
    $this->actingAs($s->user)->putJson($s->url('/enabled'), ['enabled' => false])->assertStatus(403);

    $owner = ManualTradeScenario::make('testnet', true, 'owner');
    $this->actingAs($owner->user)->putJson($owner->url('/enabled'), ['enabled' => false])->assertOk()->assertJsonPath('enabled', false);
    config()->set('quick_trade_manual.enabled', false);
    $this->putJson($owner->url('/enabled'), ['enabled' => true])->assertStatus(403);

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->getJson($owner->url('/status'))->assertStatus(403);
});

test('manual BUY/LONG opens, entry order is a market order, SL is placed AND verified, no expiry', function () {
    $s = ManualTradeScenario::make();
    $r = qtmOpen($s)->assertCreated();
    $p = P::findOrFail($r->json('id'));

    expect($p->status)->toBe(P::OPEN_PROTECTED)
        ->and($p->protection_status)->toBe('PROTECTED')
        ->and($p->mode)->toBe('manual_sl_tp')
        ->and($p->direction)->toBe('long')
        ->and((float) $p->executed_quantity)->toBe(0.012)
        ->and((float) $p->entry_price)->toBe(80000.0)
        ->and((float) $p->sl_trigger_price)->toBe(79200.0)
        ->and($p->sl_status)->toBe('WORKING')
        ->and($p->sl_algo_id)->not->toBeEmpty()
        ->and($p->sl_verified_at)->not->toBeNull()
        ->and($p->tp_status)->toBe('NONE')
        ->and($r->json('has_expiry'))->toBeFalse()
        ->and($r->json('integration_id'))->toBe($s->integration->id)
        ->and($r->json('account_label'))->toBe($s->integration->display_name)
        ->and($r->json('outcome'))->toBe('open_protected');

    // exchange side
    $algos = $s->fake->liveAlgos();
    expect($algos)->toHaveCount(1)
        ->and($algos[0]['type'])->toBe('STOP_MARKET')->and($algos[0]['side'])->toBe('SELL')
        ->and($algos[0]['trigger_price'])->toBe(79200.0)->and($algos[0]['close_position'])->toBeTrue();
    expect($s->fake->count('placeEntry'))->toBe(1)->and($s->fake->count('setLeverage'))->toBe(1);

    // audit trail in order, protection verified only AFTER placement
    $ev = qtmEvents($p);
    expect($ev)->toContain('entry_intent_recorded', 'entry_filled', 'protection_started', 'protection_verified');
    expect(array_search('entry_filled', $ev))->toBeLessThan(array_search('protection_verified', $ev));
    // never a QuickTrade row (time-based tables untouched)
    expect(QuickTrade::count())->toBe(0);
});

test('manual SELL/SHORT: stop is ABOVE entry, close side is BUY', function () {
    $s = ManualTradeScenario::make();
    $p = P::findOrFail(qtmOpen($s, ['direction' => 'short'])->assertCreated()->json('id'));
    $a = $s->fake->liveAlgos()[0];

    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($p->entry_side)->toBe('sell')
        ->and((float) $p->sl_trigger_price)->toBe(80800.0)
        ->and($a['side'])->toBe('BUY')->and($a['trigger_price'])->toBe(80800.0)
        ->and($s->fake->amount)->toBe(-0.012);
});

test('optional take-profit places a second verified exchange order', function () {
    $s = ManualTradeScenario::make();
    $p = P::findOrFail(qtmOpen($s, ['tp_pct' => 2])->assertCreated()->json('id'));
    $types = array_column($s->fake->liveAlgos(), 'type');
    expect($types)->toContain('STOP_MARKET')->and($types)->toContain('TAKE_PROFIT_MARKET')
        ->and((float) $p->tp_trigger_price)->toBe(81600.0)->and($p->tp_status)->toBe('WORKING');
});

test('take-profit omitted: exactly one protective order (the stop)', function () {
    $s = ManualTradeScenario::make();
    qtmOpen($s)->assertCreated();
    expect(array_column($s->fake->liveAlgos(), 'type'))->toBe(['STOP_MARKET']);
});

test('SL is computed from the ACTUAL fill price, not the preview price', function () {
    $s = ManualTradeScenario::make();
    $s->fake->fillSlippage = 10.0;   // buys fill 10 above mark
    $p = P::findOrFail(qtmOpen($s)->assertCreated()->json('id'));
    // fill 80010 * 0.99 = 79209.9
    expect((float) $p->entry_price)->toBe(80010.0)->and((float) $p->sl_trigger_price)->toBe(79209.9)
        ->and($s->fake->liveAlgos()[0]['trigger_price'])->toBe(79209.9)
        ->and((float) $p->entry_slippage_pct)->toBe(round(10 / 80000 * 100, 6));
});

test('partial fill: the REAL executed quantity is recorded, an event says so, and it is protected', function () {
    $s = ManualTradeScenario::make();
    $s->fake->fillRatio = 0.5;
    $p = P::findOrFail(qtmOpen($s)->assertCreated()->json('id'));
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and((float) $p->executed_quantity)->toBe(0.006)->and((float) $p->requested_quantity)->toBe(0.012)
        ->and(qtmEvents($p))->toContain('entry_partially_filled');
});

test('definite entry rejection: no position, ENTRY_FAILED, stake reservation released', function () {
    $s = ManualTradeScenario::make();
    $s->fake->entryError = \App\Domain\QuickTradeManual\Exchange\ManualExchangeException::rejected('Margin is insufficient.', -2019);
    qtmOpen($s)->assertStatus(422)->assertJsonPath('code', 'entry_rejected');

    $p = P::firstOrFail();
    $w = WalletAccount::firstOrFail();
    expect($p->status)->toBe(P::ENTRY_FAILED)->and($p->active_key)->toBeNull()->and($s->fake->amount)->toBe(0.0)
        ->and((float) $w->locked_balance)->toBe(0.0)->and((float) $w->available)->toBe(1000.0)
        ->and($s->fake->count('placeProtective'))->toBe(0);
});

test('entry whose acknowledgement is lost (timeout) is resolved by querying; never sent twice', function () {
    $s = ManualTradeScenario::make();
    $s->fake->entryError = \App\Domain\QuickTradeManual\Exchange\ManualExchangeException::unknownOutcome('timeout');
    $s->fake->entryLostAck = true;
    $p = P::findOrFail(qtmOpen($s)->assertCreated()->json('id'));
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($s->fake->count('placeEntry'))->toBe(1)->and($s->fake->amount)->toBe(0.012);
});

test('hedge-mode account: entry, stop and (later) close all carry positionSide; no reduceOnly semantics assumed', function () {
    $s = ManualTradeScenario::make();
    $s->fake->hedge = true;
    $p = P::findOrFail(qtmOpen($s)->assertCreated()->json('id'));
    $entry = collect($s->fake->calls)->firstWhere(0, 'placeEntry')[1];
    expect($p->position_mode)->toBe('hedge')->and($entry['position_side'])->toBe('LONG')
        ->and($s->fake->liveAlgos()[0]['position_side'])->toBe('LONG');
});

test('validation: stop-loss is mandatory, bounded, and take-profit must be positive', function () {
    $s = ManualTradeScenario::make();
    $this->actingAs($s->user);
    $base = $s->payload();
    unset($base['sl_pct']);
    $this->postJson($s->url('/positions'), $base)->assertStatus(422);
    $this->postJson($s->url('/positions'), $s->payload(['sl_pct' => 0]))->assertStatus(422);
    $this->postJson($s->url('/positions'), $s->payload(['sl_pct' => 40]))->assertStatus(422)->assertJsonPath('code', 'invalid_stop_loss');
    $this->postJson($s->url('/positions'), $s->payload(['tp_pct' => -1]))->assertStatus(422);
    $this->postJson($s->url('/positions'), $s->payload(['confirm' => false]))->assertStatus(422);   // separate final confirmation required
    $this->postJson($s->url('/positions'), $s->payload(['symbol' => 'DOGEUSDT']))->assertStatus(422)->assertJsonPath('code', 'invalid_symbol');
    $this->postJson($s->url('/positions'), $s->payload(['leverage' => 3.5]))->assertStatus(422)->assertJsonPath('code', 'invalid_leverage');
    expect(P::count())->toBe(0)->and($s->fake->count('placeEntry'))->toBe(0);
});

test('refusal: stale mark price', function () {
    $s = ManualTradeScenario::make();
    $s->fake->markTimeOffsetMs = -60000;
    qtmOpen($s)->assertStatus(422)->assertJsonPath('code', 'stale_mark_price');
    expect($s->fake->count('placeEntry'))->toBe(0);
});

test('refusal: mark moved more than the tolerance since the user confirmed', function () {
    $s = ManualTradeScenario::make();
    qtmOpen($s, ['reference_price' => 79000])->assertStatus(409)->assertJsonPath('code', 'price_moved');
    expect($s->fake->count('placeEntry'))->toBe(0);
    qtmOpen($s, ['reference_price' => 79990])->assertCreated();   // within 0.3 %
});

test('refusal: below exchange min notional, insufficient margin, position already on exchange', function () {
    $s = ManualTradeScenario::make();
    $s->fake->minNotional = 5000;
    qtmOpen($s)->assertStatus(422)->assertJsonPath('code', 'notional_below_minimum');
    $s->fake->minNotional = 100;
    $s->fake->balance = 50;
    qtmOpen($s)->assertStatus(422)->assertJsonPath('code', 'insufficient_margin');
    $s->fake->balance = 100000;
    $s->fake->amount = 0.5;
    qtmOpen($s)->assertStatus(422)->assertJsonPath('code', 'exchange_position_exists');
    expect($s->fake->count('placeEntry'))->toBe(0)->and(P::count())->toBe(0);
});

test('refusal: stop not safely inside liquidation distance; leverage above the exchange tier', function () {
    $s = ManualTradeScenario::make();
    qtmOpen($s, ['leverage' => 20, 'sl_pct' => 3])->assertStatus(422)->assertJsonPath('code', 'stop_loss_not_inside_liquidation');
    $s->fake->brackets = [['bracket' => 1, 'initialLeverage' => 5, 'notionalFloor' => 0, 'notionalCap' => 1000000, 'maintMarginRatio' => 0.004]];
    qtmOpen($s, ['leverage' => 10])->assertStatus(422)->assertJsonPath('code', 'exceeds_exchange_max_leverage_for_notional');
    $s->fake->brackets = null;
    qtmOpen($s)->assertStatus(422)->assertJsonPath('code', 'unable_to_verify_liquidation_distance');
    expect($s->fake->count('placeEntry'))->toBe(0);
});

test('one active manual position per symbol, a cap on open positions, and idempotent replay', function () {
    $s = ManualTradeScenario::make();
    $key = 'replay-key-0001';
    $first = qtmOpen($s, ['idempotency_key' => $key])->assertCreated()->json('id');
    $again = qtmOpen($s, ['idempotency_key' => $key])->assertCreated()->json('id');
    expect($again)->toBe($first)->and($s->fake->count('placeEntry'))->toBe(1)->and(P::count())->toBe(1);

    qtmOpen($s, ['idempotency_key' => 'another-key-0002'])->assertStatus(409)->assertJsonPath('code', 'duplicate_manual_position');
    expect($s->fake->count('placeEntry'))->toBe(1);

    config()->set('quick_trade_manual.max_open_positions', 1);
    qtmOpen($s, ['symbol' => 'ETHUSDT', 'idempotency_key' => 'third-key-00003'])->assertStatus(409)->assertJsonPath('code', 'max_open_positions');
});

test('DB-level guarantee: the unique active_key refuses a second active row for the same workspace+symbol', function () {
    $s = ManualTradeScenario::make();
    qtmOpen($s)->assertCreated();
    $row = P::firstOrFail()->replicate();
    $row->id = (string) \Illuminate\Support\Str::uuid();
    $row->idempotency_key = 'other-idem-key-1';
    expect(fn () => $row->save())->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

test('a Time-Based live trade open on the symbol blocks a manual open (one-way: same exchange position)', function () {
    $s = ManualTradeScenario::make();
    QuickTrade::create([
        'id' => (string) \Illuminate\Support\Str::uuid(), 'workspace_id' => $s->workspace->id, 'symbol' => 'BTCUSDT', 'asset_class' => 'CRYPTO',
        'side' => 'buy', 'stake_amount' => 20, 'quantity' => 0.001, 'timeframe' => '5m', 'expiry_seconds' => 3600, 'payout_ratio' => 0,
        'mode' => 'live', 'source' => 'manual_quick_trade', 'provider_key' => 'binance', 'entry_price' => 80000, 'status' => 'open',
        'entry_at' => now(), 'expires_at' => now()->addHour(),
    ]);
    qtmOpen($s)->assertStatus(409)->assertJsonPath('code', 'time_based_position_open');
    expect($s->fake->count('placeEntry'))->toBe(0);
});

test('stake is reserved on open and the wallet mirrors it', function () {
    $s = ManualTradeScenario::make();
    qtmOpen($s)->assertCreated();
    $w = WalletAccount::firstOrFail();
    expect((float) $w->locked_balance)->toBe(100.0)->and((float) $w->available)->toBe(900.0);
});

test('the preview returns price-based SL math, trigger, notional, fees, liquidation and warnings', function () {
    $s = ManualTradeScenario::make();
    $this->actingAs($s->user);
    $r = $this->postJson($s->url('/preview'), $s->payload(['tp_pct' => 2]))->assertOk();
    expect($r->json('sl_basis'))->toBe('price_movement')
        ->and($r->json('sl_trigger_price'))->toBe(79200)
        ->and($r->json('quantity'))->toBe(0.012)
        ->and($r->json('notional'))->toBe(960)
        ->and($r->json('take_profit.trigger_price'))->toBe(81600)
        ->and($r->json('estimated_liquidation_price'))->not->toBeNull()
        ->and($r->json('warnings'))->not->toBeEmpty()
        ->and($r->json('ok'))->toBeTrue()
        ->and($s->fake->count('placeEntry'))->toBe(0);
});
