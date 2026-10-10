<?php

use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use App\Domain\QuickTradeManual\ManualPositionReconciler;
use App\Models\QuickTradeManualEvent;
use App\Models\QuickTradeManualPosition as P;
use App\Models\WalletAccount;
use Tests\Support\ManualTradeScenario;

function qtmOpenP(ManualTradeScenario $s, array $over = []): P
{
    test()->actingAs($s->user);
    $r = test()->postJson($s->url('/positions'), $s->payload($over))->assertCreated();

    return P::findOrFail($r->json('id'));
}

function qtmTypes(P $p): array
{
    return QuickTradeManualEvent::query()->where('position_id', $p->id)->orderBy('seq')->pluck('type')->all();
}

test('protective order rejected by the exchange: immediate emergency close, exchange-confirmed flat, critical alert, never PROTECTED', function () {
    $s = ManualTradeScenario::make();
    $s->fake->protectiveError = ManualExchangeException::rejected('Order type not supported for this endpoint', -4120);
    $p = qtmOpenP($s)->refresh();

    expect($p->status)->toBe(P::CLOSED)
        ->and($p->exit_reason)->toBe('emergency_protection_failed')
        ->and($p->closed_by)->toBe('system')
        ->and((bool) $p->emergency_close_confirmed)->toBeTrue()
        ->and((bool) $p->critical_alert)->toBeTrue()
        ->and($p->protection_status)->toBe('FAILED')
        ->and($p->active_key)->toBeNull()
        ->and($s->fake->amount)->toBe(0.0)                         // the EXCHANGE shows flat
        ->and($s->fake->count('placeClose'))->toBe(1);
    $types = qtmTypes($p);
    expect($types)->toContain('protection_failed', 'emergency_exit_started', 'emergency_exit_confirmed', 'position_finalized')
        ->and($types)->not->toContain('protection_verified');
    expect(QuickTradeManualEvent::where('position_id', $p->id)->where('severity', 'critical')->count())->toBeGreaterThanOrEqual(2);
    // money: stake released with the (small) real loss booked
    $w = WalletAccount::firstOrFail();
    expect((float) $w->locked_balance)->toBe(0.0)->and($p->wallet_settled_at)->not->toBeNull();
});

test('acknowledged but NOT verifiable by query: not protected, emergency close', function () {
    $s = ManualTradeScenario::make();
    $s->fake->protectiveAckOnlyUnverifiable = true;
    $p = qtmOpenP($s)->refresh();
    expect($p->status)->toBe(P::CLOSED)->and($p->exit_reason)->toBe('emergency_protection_failed')
        ->and(qtmTypes($p))->not->toContain('protection_verified')
        ->and($s->fake->liveAlgos())->toBe([]);                    // the unverified order was cancelled, no ghost left
});

test('exchange answers with a non-working status (e.g. EXPIRED): not protected, emergency close', function () {
    $s = ManualTradeScenario::make();
    $s->fake->protectiveStatus = 'EXPIRED';
    $p = qtmOpenP($s)->refresh();
    expect($p->status)->toBe(P::CLOSED)->and($p->sl_status)->not->toBe('WORKING')->and(qtmTypes($p))->not->toContain('protection_verified');
});

test('placement timeout (unknown outcome) with nothing on the exchange: emergency close, no blind re-place', function () {
    $s = ManualTradeScenario::make();
    $s->fake->protectiveError = ManualExchangeException::unknownOutcome('timeout');
    $p = qtmOpenP($s)->refresh();
    expect($p->status)->toBe(P::CLOSED)->and($s->fake->count('placeProtective'))->toBe(1);
});

test('stop not safely inside the EXCHANGE liquidation price after the fill: protection invalid, emergency close, stop removed', function () {
    $s = ManualTradeScenario::make();
    $s->fake->liqPrice = 79500.0;           // long: liquidation only 500 away, stop 800 away
    $p = qtmOpenP($s)->refresh();
    expect($p->status)->toBe(P::CLOSED)->and($p->exit_reason)->toBe('emergency_liquidation_risk')
        ->and($s->fake->liveAlgos())->toBe([]);
});

test('emergency close that the exchange does not confirm is CLOSE_FAILED + critical, NEVER reported as closed; reconciler retries until flat', function () {
    $s = ManualTradeScenario::make();
    $s->fake->protectiveError = ManualExchangeException::rejected('nope', -4120);
    $s->fake->closeNoEffect = true;
    $p = qtmOpenP($s)->refresh();

    expect($p->status)->toBe(P::CLOSE_FAILED)
        ->and((bool) $p->emergency_close_confirmed)->toBeFalse()
        ->and((bool) $p->critical_alert)->toBeTrue()
        ->and($p->active_key)->not->toBeNull()                      // slot still held: the position is real
        ->and($p->closed_at)->toBeNull()
        ->and($s->fake->amount)->toBe(0.012);
    $r = test()->getJson($s->url('/positions/'.$p->id))->assertOk();
    expect($r->json('outcome'))->toBe('close_unconfirmed_position_may_be_unprotected')->and($r->json('is_terminal'))->toBeFalse();

    // exchange recovers; the reconciler (no user action) finishes the job
    $s->fake->closeNoEffect = false;
    app(ManualPositionReconciler::class)->reconcileOne($p->refresh());
    $p->refresh();
    expect($p->status)->toBe(P::CLOSED)->and((bool) $p->emergency_close_confirmed)->toBeTrue()->and($s->fake->amount)->toBe(0.0);
});

test('a failed take-profit does not fail the position: still SL-protected, the TP gap is reported honestly', function () {
    $s = ManualTradeScenario::make();
    $s->fake->tpRejected = true;
    $p = qtmOpenP($s, ['tp_pct' => 2])->refresh();
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($p->sl_status)->toBe('WORKING')->and($p->tp_status)->toBe('FAILED')
        ->and(qtmTypes($p))->toContain('take_profit_not_active')
        ->and(array_column($s->fake->liveAlgos(), 'type'))->toBe(['STOP_MARKET']);
});
