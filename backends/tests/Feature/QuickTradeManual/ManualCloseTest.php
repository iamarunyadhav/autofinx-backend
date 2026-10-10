<?php

use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use App\Domain\QuickTradeManual\ManualPositionReconciler;
use App\Models\QuickTradeManualEvent;
use App\Models\QuickTradeManualPosition as P;
use App\Models\WalletAccount;
use Tests\Support\ManualTradeScenario;

function qtmOpenForClose(ManualTradeScenario $s, array $over = []): P
{
    test()->actingAs($s->user);

    return P::findOrFail(test()->postJson($s->url('/positions'), $s->payload($over))->assertCreated()->json('id'));
}

function qtmCloseUrl(ManualTradeScenario $s, P $p, string $suffix = 'close'): string
{
    return $s->url('/positions/'.$p->id.'/'.$suffix);
}

test('manual close: reduce-only market order, exchange confirms flat, stops cancelled, real P&L and fees booked, wallet settled once', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s, ['tp_pct' => 2]);
    $s->fake->mark = 80500.0;

    $r = test()->postJson(qtmCloseUrl($s, $p))->assertOk();
    $p->refresh();

    $close = collect($s->fake->calls)->firstWhere(0, 'placeClose')[1];
    expect($close['side'])->toBe('SELL')->and($close['quantity'])->toBe('0.012')->and($close['position_side'])->toBeNull();   // one-way => reduce-only in the gateway
    expect($p->status)->toBe(P::CLOSED)
        ->and($p->exit_reason)->toBe('manual')->and($p->closed_by)->toBe('user')
        ->and($s->fake->amount)->toBe(0.0)
        ->and($s->fake->liveAlgos())->toBe([])                       // SL AND TP removed from the exchange
        ->and($p->sl_status)->toBe('CANCELLED')->and($p->tp_status)->toBe('CANCELLED')
        ->and((float) $p->exit_price)->toBe(80500.0)
        ->and((float) $p->gross_pnl)->toBe(6.0)                      // (80500-80000) x 0.012
        ->and((float) $p->entry_fee)->toBe(0.48)                     // real commission from fills
        ->and((float) $p->exit_fee)->toBe(0.483)
        ->and((float) $p->net_pnl)->toBe(5.037)
        ->and($p->pnl_source)->toBe('real')
        ->and($r->json('status'))->toBe('CLOSED');

    $w = WalletAccount::firstOrFail();
    expect((float) $w->locked_balance)->toBe(0.0)->and((float) $w->balance)->toBe(1005.037)->and((float) $w->available)->toBe(1005.037);
});

test('funding is real and signed: a charged funding fee is a cost, a credit reduces cost', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s);
    $s->fake->mark = 80500.0;
    $s->fake->fundingIncome = -0.2;       // Binance income negative = charged
    test()->postJson(qtmCloseUrl($s, $p))->assertOk();
    $p->refresh();
    expect((float) $p->funding_fee)->toBe(0.2)->and((float) $p->net_pnl)->toBe(round(6.0 - 0.48 - 0.483 - 0.2, 8));
});

test('duplicate close request: exactly one exchange close order', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s);
    test()->postJson(qtmCloseUrl($s, $p))->assertOk();
    test()->postJson(qtmCloseUrl($s, $p))->assertOk()->assertJsonPath('status', 'CLOSED');
    expect($s->fake->count('placeClose'))->toBe(1);

    $p2 = qtmOpenForClose($s, ['symbol' => 'ETHUSDT']);
    P::whereKey($p2->id)->update(['status' => P::CLOSING]);          // a close already in flight
    test()->postJson(qtmCloseUrl($s, $p2))->assertOk()->assertJsonPath('status', 'CLOSING');
    expect($s->fake->count('placeClose'))->toBe(1);
});

test('SL vs manual close race (stop fired first): no close order is sent, exit is attributed to the stop, real fills booked', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s);
    $s->fake->triggerAlgo('STOP_MARKET', 79200.0);                  // exchange closed it before the click

    test()->postJson(qtmCloseUrl($s, $p))->assertOk();
    $p->refresh();
    expect($s->fake->count('placeClose'))->toBe(0)
        ->and($p->status)->toBe(P::CLOSED)->and($p->exit_reason)->toBe('stop_loss')->and($p->closed_by)->toBe('exchange')
        ->and((float) $p->exit_price)->toBe(79200.0)
        ->and((float) $p->gross_pnl)->toBe(-9.6)
        ->and((float) $p->net_pnl)->toBe(round(-9.6 - 0.48 - 0.4752, 8))
        ->and(QuickTradeManualEvent::where('position_id', $p->id)->where('type', 'manual_close_lost_race_position_already_flat')->exists())->toBeTrue();
});

test('SL fires between our position check and our order: reduce-only reject is verified against the exchange position, never trusted blindly', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s);
    $s->fake->beforeClose = fn ($f) => $f->triggerAlgo('STOP_MARKET', 79200.0);
    test()->postJson(qtmCloseUrl($s, $p))->assertOk();
    $p->refresh();
    expect($p->status)->toBe(P::CLOSED)->and($p->exit_reason)->toBe('stop_loss')->and($s->fake->amount)->toBe(0.0);
});

test('a reduce-only rejection while the position is STILL open is a failed close, not a success', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s);
    $s->fake->closeError = ManualExchangeException::rejected('ReduceOnly Order is rejected.', -2022);
    test()->postJson(qtmCloseUrl($s, $p))->assertOk()->assertJsonPath('status', 'CLOSE_FAILED');
    expect($s->fake->amount)->toBe(0.012);
});

test('failed manual close: CLOSE_FAILED, stop stays on the exchange, reconciler returns to OPEN_PROTECTED, user can retry and succeed', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s);
    $s->fake->closeError = ManualExchangeException::rejected('Exchange is under maintenance', -1000);
    test()->postJson(qtmCloseUrl($s, $p))->assertOk()->assertJsonPath('status', 'CLOSE_FAILED');
    expect($s->fake->liveAlgos())->toHaveCount(1);

    app(ManualPositionReconciler::class)->reconcileOne($p->refresh());
    expect($p->refresh()->status)->toBe(P::OPEN_PROTECTED)->and($p->protection_status)->toBe('PROTECTED');

    $s->fake->closeError = null;
    test()->postJson(qtmCloseUrl($s, $p))->assertOk()->assertJsonPath('status', 'CLOSED');
    expect($s->fake->count('placeClose'))->toBe(2);
});

test('close whose acknowledgement is lost is resolved by the exchange position, not assumed', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s);
    $s->fake->closeLostAck = true;
    test()->postJson(qtmCloseUrl($s, $p))->assertOk();
    expect($p->refresh()->status)->toBe(P::CLOSED)->and($s->fake->count('placeClose'))->toBe(1);
});

test('close works even with both feature flags switched off (a flag must never trap an open position)', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s);
    config()->set('quick_trade_manual.enabled', false);
    test()->postJson(qtmCloseUrl($s, $p))->assertOk()->assertJsonPath('status', 'CLOSED');
});

test('close preview shows symbol, direction, quantity, mark, gross/net estimate, fees and a slippage warning', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s);
    $s->fake->mark = 80500.0;
    $r = test()->postJson(qtmCloseUrl($s, $p, 'close-preview'))->assertOk();
    expect($r->json('symbol'))->toBe('BTCUSDT')->and($r->json('direction'))->toBe('long')->and($r->json('quantity'))->toBe(0.012)
        ->and($r->json('mark_price'))->toBe(80500)->and($r->json('estimated_gross_pnl'))->toBe(6)
        ->and($r->json('estimated_exit_fee'))->toBe(0.483)->and($r->json('estimated_net_pnl'))->toBe(round(6 - 0.48 - 0.483, 8))
        ->and($r->json('slippage_warning'))->toContain('MARKET');
    expect($s->fake->count('placeClose'))->toBe(0);
});

test('short position close uses a BUY and the sign of P&L is reversed', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s, ['direction' => 'short']);
    $s->fake->mark = 79500.0;
    test()->postJson(qtmCloseUrl($s, $p))->assertOk();
    $p->refresh();
    $close = collect($s->fake->calls)->firstWhere(0, 'placeClose')[1];
    expect($close['side'])->toBe('BUY')->and((float) $p->gross_pnl)->toBe(6.0)->and($p->status)->toBe(P::CLOSED);
});

test('exit fills not yet available: stays RECONCILIATION (no invented numbers) and then books flagged-estimated after several tries', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s);
    $s->fake->mark = 80500.0;
    $s->fake->fillsUnavailable = true;
    test()->postJson(qtmCloseUrl($s, $p))->assertOk()->assertJsonPath('status', 'RECONCILIATION');
    $rec = app(ManualPositionReconciler::class);
    for ($i = 0; $i < 4; $i++) {
        $rec->reconcileOne($p->refresh());
    }
    expect($p->refresh()->status)->toBe(P::RECONCILIATION)->and($p->net_pnl)->toBeNull();
    $rec->reconcileOne($p->refresh());
    $p->refresh();
    expect($p->status)->toBe(P::CLOSED)->and($p->pnl_source)->toBe('estimated');
    // real fills arrive later in other runs; wallet was settled exactly once
    $rec->reconcileOne($p->refresh());
    expect((float) WalletAccount::firstOrFail()->locked_balance)->toBe(0.0);
});

test('another workspace member cannot touch a position of a workspace they do not belong to', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForClose($s);
    $other = ManualTradeScenario::make();
    test()->actingAs($other->user)->postJson(qtmCloseUrl($s, $p))->assertStatus(403);
    test()->actingAs($other->user)->getJson($s->url('/positions/'.$p->id))->assertStatus(403);
    expect($s->fake->count('placeClose'))->toBe(0);
});
