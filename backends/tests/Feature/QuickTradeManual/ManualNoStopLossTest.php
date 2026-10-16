<?php

use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use App\Domain\QuickTradeManual\ManualPositionReconciler;
use App\Models\QuickTradeManualEvent;
use App\Models\QuickTradeManualPosition as P;
use App\Models\WalletAccount;
use Tests\Support\ManualTradeScenario;

/** Payload for an explicit no-stop-loss trade (lower leverage: the no-stop cap defaults to 5x). */
function qtmNoSl(ManualTradeScenario $s, array $over = []): array
{
    $p = $s->payload(array_merge(['leverage' => 5, 'no_stop_loss' => true, 'no_stop_loss_ack' => true], $over));
    unset($p['sl_pct']);

    return array_merge($p, array_intersect_key($over, ['sl_pct' => 1]));
}

function qtmNoSlOpen(ManualTradeScenario $s, array $over = []): P
{
    test()->actingAs($s->user);

    return P::findOrFail(test()->postJson($s->url('/positions'), qtmNoSl($s, $over))->assertCreated()->json('id'));
}

function qtmNoSlRec(P $p): P
{
    return app(ManualPositionReconciler::class)->reconcileOne($p->refresh(), 'reconciler')->refresh();
}

function qtmNoSlEv(P $p, string $type): int
{
    return QuickTradeManualEvent::where('position_id', $p->id)->where('type', $type)->count();
}

beforeEach(function () {
    config()->set('quick_trade_manual.allow_no_stop_loss', true);
});

test('config defaults: allow_no_stop_loss is OFF and the no-stop caps are conservative', function () {
    $cfg = require base_path('config/quick_trade_manual.php');
    expect($cfg['allow_no_stop_loss'])->toBeFalse()->and($cfg['no_stop_loss_max_leverage'])->toBe(5.0)->and($cfg['no_stop_loss_require_isolated'])->toBeTrue();
});

test('flag OFF: the opt-in is rejected (403), nothing is sent to the exchange, no row, no reservation', function () {
    config()->set('quick_trade_manual.allow_no_stop_loss', false);
    $s = ManualTradeScenario::make();
    $this->actingAs($s->user);
    $this->postJson($s->url('/positions'), qtmNoSl($s))->assertStatus(403)->assertJsonPath('code', 'no_stop_loss_disabled');
    $this->postJson($s->url('/preview'), qtmNoSl($s))->assertStatus(403)->assertJsonPath('code', 'no_stop_loss_disabled');
    expect(P::count())->toBe(0)->and($s->fake->count('placeEntry'))->toBe(0)->and(WalletAccount::query()->sum('locked_balance'))->toEqual(0);
});

test('a missing / blank stop-loss WITHOUT the explicit opt-in is still rejected, even when the flag is on', function () {
    $s = ManualTradeScenario::make();
    $this->actingAs($s->user);
    $base = $s->payload();
    unset($base['sl_pct']);
    $this->postJson($s->url('/positions'), $base)->assertStatus(422)->assertJsonPath('code', 'stop_loss_required');
    $this->postJson($s->url('/positions'), array_merge($base, ['sl_pct' => '']))->assertStatus(422)->assertJsonPath('code', 'stop_loss_required');
    $this->postJson($s->url('/positions'), array_merge($base, ['sl_pct' => null]))->assertStatus(422)->assertJsonPath('code', 'stop_loss_required');
    // ack alone, or an explicit false, is not an opt-in
    $this->postJson($s->url('/positions'), array_merge($base, ['no_stop_loss_ack' => true]))->assertStatus(422)->assertJsonPath('code', 'stop_loss_required');
    $this->postJson($s->url('/positions'), array_merge($base, ['no_stop_loss' => false, 'no_stop_loss_ack' => true]))->assertStatus(422)->assertJsonPath('code', 'stop_loss_required');
    expect(P::count())->toBe(0)->and($s->fake->count('placeEntry'))->toBe(0);
});

test('opt-in without the acknowledgement is rejected', function () {
    $s = ManualTradeScenario::make();
    $this->actingAs($s->user);
    foreach ([['no_stop_loss_ack' => false], ['no_stop_loss_ack' => null]] as $over) {
        $this->postJson($s->url('/positions'), qtmNoSl($s, $over))->assertStatus(422)->assertJsonPath('code', 'no_stop_loss_ack_required');
    }
    $payload = qtmNoSl($s);
    unset($payload['no_stop_loss_ack']);
    $this->postJson($s->url('/positions'), $payload)->assertStatus(422)->assertJsonPath('code', 'no_stop_loss_ack_required');
    expect(P::count())->toBe(0)->and($s->fake->count('placeEntry'))->toBe(0);
});

test('opt-in is refused when it conflicts or breaches the no-stop caps', function () {
    $s = ManualTradeScenario::make();
    $this->actingAs($s->user);
    $this->postJson($s->url('/positions'), qtmNoSl($s, ['sl_pct' => 1]))->assertStatus(422)->assertJsonPath('code', 'stop_loss_conflict');
    $this->postJson($s->url('/positions'), qtmNoSl($s, ['leverage' => 10]))->assertStatus(422)->assertJsonPath('code', 'no_stop_loss_leverage_too_high');
    $this->postJson($s->url('/positions'), qtmNoSl($s, ['trailing_pct' => 1]))->assertStatus(422)->assertJsonPath('code', 'trailing_requires_stop_loss');
    $s->fake->marginType = 'cross';
    $this->postJson($s->url('/positions'), qtmNoSl($s))->assertStatus(422)->assertJsonPath('code', 'no_stop_loss_requires_isolated_margin');
    expect(P::count())->toBe(0)->and($s->fake->count('placeEntry'))->toBe(0);
});

test('opt-in accepted: market entry, NO stop order of any kind, open and managed, opt-in audited on the record', function () {
    $s = ManualTradeScenario::make();
    $this->actingAs($s->user);
    $r = $this->postJson($s->url('/positions'), qtmNoSl($s))->assertCreated();
    $p = P::findOrFail($r->json('id'));

    expect($p->status)->toBe(P::OPEN_PROTECTED)
        ->and($p->protection_status)->toBe(P::PS_NO_STOP)
        ->and($p->noStopLoss())->toBeTrue()
        ->and((float) $p->sl_pct)->toBe(0.0)
        ->and($p->sl_trigger_price)->toBeNull()->and($p->sl_algo_id)->toBeNull()->and($p->sl_status)->toBe('NONE')
        ->and($p->protected_at)->toBeNull()
        ->and((float) $p->executed_quantity)->toBe(0.006)
        ->and($p->estimated_liquidation_price)->not->toBeNull()
        ->and($r->json('no_stop_loss'))->toBeTrue()->and($r->json('sl_pct'))->toBeNull()->and($r->json('sl_trigger_price'))->toBeNull()
        ->and($r->json('outcome'))->toBe('open_no_stop_loss')->and($r->json('has_expiry'))->toBeFalse()->and($r->json('critical_alert'))->toBeFalse();

    // exchange side: no stop, no conditional order at all
    expect($s->fake->count('placeProtective'))->toBe(0)->and($s->fake->liveAlgos())->toBe([])->and($s->fake->count('placeEntry'))->toBe(1);

    // audit: the opt-in + ack + who, on the append-only record, before the order; plus an explicit open event
    $intent = QuickTradeManualEvent::where('position_id', $p->id)->where('type', 'entry_intent_recorded')->firstOrFail();
    expect($intent->payload['no_stop_loss'])->toBeTrue()->and($intent->payload['no_stop_loss_acknowledged'])->toBeTrue()
        ->and($intent->payload['acknowledged_by'])->toBe($s->user->id)
        ->and(qtmNoSlEv($p, 'open_without_stop_loss'))->toBe(1)->and(qtmNoSlEv($p, 'protection_verified'))->toBe(0);
});

test('take-profit still works with no stop: exactly one conditional order, the take-profit', function () {
    $s = ManualTradeScenario::make();
    $p = qtmNoSlOpen($s, ['tp_pct' => 2]);
    expect(array_column($s->fake->liveAlgos(), 'type'))->toBe(['TAKE_PROFIT_MARKET'])
        ->and($p->tp_status)->toBe('WORKING')->and((float) $p->tp_trigger_price)->toBe(81600.0)->and($p->status)->toBe(P::OPEN_PROTECTED);
});

test('a failed take-profit does not fail or close a no-stop position', function () {
    $s = ManualTradeScenario::make();
    $s->fake->tpRejected = true;
    $p = qtmNoSlOpen($s, ['tp_pct' => 2]);
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($p->tp_status)->toBe('FAILED')->and($s->fake->amount)->toBe(0.006)
        ->and(qtmNoSlEv($p, 'take_profit_not_active'))->toBe(1)->and($s->fake->count('placeClose'))->toBe(0);
});

test('preview with no stop: no stop numbers, a loud warning, a liquidation price, and the opt-in is checked', function () {
    $s = ManualTradeScenario::make();
    $this->actingAs($s->user);
    $payload = qtmNoSl($s);
    unset($payload['no_stop_loss_ack']);   // the ack belongs to the final confirm, not the read-only preview
    $r = $this->postJson($s->url('/preview'), $payload)->assertOk();
    expect($r->json('ok'))->toBeTrue()->and($r->json('no_stop_loss'))->toBeTrue()
        ->and($r->json('sl_trigger_price'))->toBeNull()->and($r->json('estimated_loss_at_sl'))->toBeNull()
        ->and($r->json('estimated_liquidation_price'))->not->toBeNull()
        ->and($r->json('max_loss_without_stop_estimate'))->toEqual(100)
        ->and(implode(' ', $r->json('warnings')))->toContain('NO STOP-LOSS')
        ->and($s->fake->count('placeEntry'))->toBe(0);

    // and without the opt-in the preview of a stop-less order is still rejected
    $bare = $s->payload();
    unset($bare['sl_pct']);
    $this->postJson($s->url('/preview'), $bare)->assertStatus(422)->assertJsonPath('code', 'stop_loss_required');
});

test('reconciler: a no-stop position is NOT "missing a stop": no incident, no re-placement, no close, idempotent', function () {
    $s = ManualTradeScenario::make();
    $p = qtmNoSlOpen($s);
    for ($i = 0; $i < 3; $i++) {
        $p = qtmNoSlRec($p);
    }
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($p->protection_status)->toBe(P::PS_NO_STOP)->and((bool) $p->critical_alert)->toBeFalse()
        ->and($p->last_reconciled_at)->not->toBeNull()
        ->and(qtmNoSlEv($p, 'protection_lost'))->toBe(0)->and(qtmNoSlEv($p, 'protection_failed'))->toBe(0)->and(qtmNoSlEv($p, 'emergency_exit_started'))->toBe(0)
        ->and($s->fake->count('placeProtective'))->toBe(0)->and($s->fake->count('placeClose'))->toBe(0)->and($s->fake->amount)->toBe(0.006);
});

test('reconciler: a missing take-profit on a no-stop position is reported as a TP problem only, never closes the position', function () {
    $s = ManualTradeScenario::make();
    $p = qtmNoSlOpen($s, ['tp_pct' => 2]);
    $s->fake->cancelProtective('BTCUSDT', $p->tp_algo_id, null);
    $p = qtmNoSlRec($p);
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($p->tp_status)->toBe('LOST')->and(qtmNoSlEv($p, 'take_profit_lost'))->toBe(1)
        ->and(qtmNoSlEv($p, 'protection_lost'))->toBe(0)->and($s->fake->count('placeClose'))->toBe(0);
});

test('reconciler: unreadable exchange then recovery returns to open-without-stop, never an emergency close', function () {
    $s = ManualTradeScenario::make();
    $p = qtmNoSlOpen($s);
    $s->fake->readsFail = true;
    for ($i = 0; $i < 3; $i++) {
        $p = qtmNoSlRec($p);
    }
    expect($p->status)->toBe(P::EXCHANGE_STATE_UNKNOWN)->and($p->protection_status)->toBe(P::PS_NO_STOP)->and($s->fake->count('placeClose'))->toBe(0);

    $s->fake->readsFail = false;
    $p = qtmNoSlRec($p);
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($p->protection_status)->toBe(P::PS_NO_STOP)->and($s->fake->amount)->toBe(0.006)->and($s->fake->count('placeClose'))->toBe(0);
});

test('external close / liquidation of a no-stop position is attributed honestly (never to a stop)', function () {
    $s = ManualTradeScenario::make();
    $p = qtmNoSlOpen($s);
    $s->fake->externalClose(80300.0);
    $p = qtmNoSlRec($p);
    expect($p->status)->toBe(P::CLOSED)->and($p->exit_reason)->toBe('external')->and((float) $p->gross_pnl)->toBe(1.8);

});

test('liquidation of a no-stop position: terminal LIQUIDATED with the real loss', function () {
    $s = ManualTradeScenario::make();
    $p = qtmNoSlOpen($s);
    $s->fake->externalClose(65000.0);
    $s->fake->liquidated = true;
    $p = qtmNoSlRec($p);
    expect($p->status)->toBe(P::LIQUIDATED)->and($p->exit_reason)->toBe('liquidation')->and($p->active_key)->toBeNull();
});

test('exchange take-profit fires on a no-stop position: attributed to TP, booked CLOSED', function () {
    $s = ManualTradeScenario::make();
    $p = qtmNoSlOpen($s, ['tp_pct' => 2]);
    $s->fake->triggerAlgo('TAKE_PROFIT_MARKET', 81600.0);
    $p = qtmNoSlRec($p);
    expect($p->status)->toBe(P::CLOSED)->and($p->exit_reason)->toBe('take_profit')->and((float) $p->gross_pnl)->toBe(9.6);
});

test('manual close of a no-stop position: reduce-only market close, flat confirmed, wallet settled once', function () {
    $s = ManualTradeScenario::make();
    $p = qtmNoSlOpen($s, ['tp_pct' => 2]);
    $s->fake->mark = 80500.0;

    $this->postJson($s->url('/positions/'.$p->id.'/close-preview'))->assertOk();
    $this->postJson($s->url('/positions/'.$p->id.'/close'))->assertOk()->assertJsonPath('status', 'CLOSED');
    $this->postJson($s->url('/positions/'.$p->id.'/close'))->assertOk();   // double click: no second order

    $p->refresh();
    expect($p->status)->toBe(P::CLOSED)->and($p->exit_reason)->toBe('manual')->and($p->closed_by)->toBe('user')
        ->and($s->fake->amount)->toBe(0.0)->and($s->fake->count('placeClose'))->toBe(1)
        ->and($s->fake->liveAlgos())->toBe([])                    // the optional TP was cleaned up
        ->and((float) $p->gross_pnl)->toBe(3.0);
    expect((float) WalletAccount::firstOrFail()->locked_balance)->toBe(0.0);
});

test('a failed manual close of a no-stop position goes back to open (user retries); the reconciler never auto-closes it', function () {
    $s = ManualTradeScenario::make();
    $p = qtmNoSlOpen($s);
    $s->fake->closeNoEffect = true;
    $this->postJson($s->url('/positions/'.$p->id.'/close'))->assertOk();
    $p->refresh();
    expect($p->status)->toBe(P::CLOSE_FAILED)->and($s->fake->count('placeClose'))->toBe(1);

    $p = qtmNoSlRec($p);
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($p->protection_status)->toBe(P::PS_NO_STOP)
        ->and(qtmNoSlEv($p, 'emergency_exit_started'))->toBe(0)->and($s->fake->count('placeClose'))->toBe(1)
        ->and((bool) $p->emergency_close_confirmed)->toBeFalse();

    $s->fake->closeNoEffect = false;
    $this->postJson($s->url('/positions/'.$p->id.'/close'))->assertOk()->assertJsonPath('status', 'CLOSED');
});

test('entry crash recovery: a no-stop entry resumed by the reconciler ends open-without-stop, no stop is ever placed', function () {
    $s = ManualTradeScenario::make();
    $s->fake->entryError = ManualExchangeException::unknownOutcome('timeout');
    $s->fake->entryLostAck = true;
    $p = qtmNoSlOpen($s);
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($p->protection_status)->toBe(P::PS_NO_STOP)
        ->and($s->fake->count('placeEntry'))->toBe(1)->and($s->fake->count('placeProtective'))->toBe(0);
});

test('a normal (stop-loss) trade is unchanged when the flag is on', function () {
    $s = ManualTradeScenario::make();
    $this->actingAs($s->user);
    $p = P::findOrFail($this->postJson($s->url('/positions'), $s->payload())->assertCreated()->json('id'));
    expect($p->noStopLoss())->toBeFalse()->and($p->protection_status)->toBe('PROTECTED')->and($p->sl_status)->toBe('WORKING')
        ->and(array_column($s->fake->liveAlgos(), 'type'))->toBe(['STOP_MARKET']);
});

test('status endpoint exposes the flag so the UI can hide the opt-in when it is off', function () {
    $s = ManualTradeScenario::make();
    $this->actingAs($s->user);
    $this->getJson($s->url('/status'))->assertOk()->assertJsonPath('allow_no_stop_loss', true)->assertJsonPath('limits.no_stop_loss_max_leverage', 5);
    config()->set('quick_trade_manual.allow_no_stop_loss', false);
    $this->getJson($s->url('/status'))->assertOk()->assertJsonPath('allow_no_stop_loss', false);
});
