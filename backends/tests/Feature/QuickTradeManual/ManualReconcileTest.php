<?php

use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use App\Domain\QuickTradeManual\ManualPositionReconciler;
use App\Models\QuickTradeManualEvent;
use App\Models\QuickTradeManualPosition as P;
use App\Models\WalletAccount;
use Illuminate\Support\Str;
use Tests\Support\ManualTradeScenario;

function qtmOpenForRec(ManualTradeScenario $s, array $over = []): P
{
    test()->actingAs($s->user);

    return P::findOrFail(test()->postJson($s->url('/positions'), $s->payload($over))->assertCreated()->json('id'));
}

function qtmRec(P $p, string $source = 'reconciler'): P
{
    return app(ManualPositionReconciler::class)->reconcileOne($p->refresh(), $source)->refresh();
}

function qtmEv(P $p, string $type): int
{
    return QuickTradeManualEvent::where('position_id', $p->id)->where('type', $type)->count();
}

test('exchange stop-loss fired while nobody was looking (browser closed / worker restarted): reconciler books CLOSED with real exchange numbers', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s, ['tp_pct' => 2]);
    $s->fake->triggerAlgo('STOP_MARKET', 79200.0);

    // a brand-new reconciler instance = a restarted worker; the DB + exchange are all it has
    $p = qtmRec($p);
    expect($p->status)->toBe(P::CLOSED)->and($p->exit_reason)->toBe('stop_loss')->and($p->closed_by)->toBe('exchange')
        ->and((float) $p->exit_price)->toBe(79200.0)->and((float) $p->gross_pnl)->toBe(-9.6)
        ->and($p->tp_status)->toBe('CANCELLED')                              // the sibling take-profit was cleaned up
        ->and($s->fake->liveAlgos())->toBe([])
        ->and($s->fake->count('placeClose'))->toBe(0);
    $w = WalletAccount::firstOrFail();
    expect((float) $w->locked_balance)->toBe(0.0);
});

test('exchange take-profit fired: attributed to TP, sibling SL cancelled, profit booked', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s, ['tp_pct' => 2]);
    $s->fake->triggerAlgo('TAKE_PROFIT_MARKET', 81600.0);
    $p = qtmRec($p);
    expect($p->status)->toBe(P::CLOSED)->and($p->exit_reason)->toBe('take_profit')->and($p->sl_status)->toBe('CANCELLED')
        ->and((float) $p->gross_pnl)->toBe(19.2)->and($s->fake->liveAlgos())->toBe([]);
});

test('SL/TP cancellation reconciliation: both legs end CANCELLED/TRIGGERED, none left live, even if the exchange did not auto-cancel the sibling', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s, ['tp_pct' => 2]);
    $s->fake->triggerAlgo('STOP_MARKET', 79200.0);
    $p = qtmRec($p);
    expect([$p->sl_status, $p->tp_status])->toBe(['TRIGGERED', 'CANCELLED']);
});

test('closed outside the app with no SL/TP evidence: external closure, flagged, real fills booked', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    $s->fake->externalClose(80300.0);
    $p = qtmRec($p);
    expect($p->status)->toBe(P::CLOSED)->and($p->exit_reason)->toBe('external')->and((float) $p->gross_pnl)->toBe(3.6)
        ->and($s->fake->liveAlgos())->toBe([])
        ->and(QuickTradeManualEvent::where('position_id', $p->id)->where('type', 'exchange_exit_detected')->where('severity', 'high')->exists())->toBeTrue();
});

test('liquidation: terminal LIQUIDATED with the real loss', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    $s->fake->externalClose(72000.0);
    $s->fake->liquidated = true;
    $p = qtmRec($p);
    expect($p->status)->toBe(P::LIQUIDATED)->and($p->exit_reason)->toBe('liquidation')->and($p->active_key)->toBeNull()
        ->and((float) $p->gross_pnl)->toBe(-96.0)->and($p->net_pnl)->toBeLessThan(-96.0);
});

test('open position with healthy protection: reconcile is a no-op that only stamps last_reconciled_at (idempotent, no duplicate orders)', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    $placed = $s->fake->count('placeProtective');
    $p = qtmRec($p);
    $p = qtmRec($p);
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($p->last_reconciled_at)->not->toBeNull()
        ->and($s->fake->count('placeProtective'))->toBe($placed)->and($s->fake->count('placeEntry'))->toBe(1)->and($s->fake->count('placeClose'))->toBe(0);
});

test('stop-loss vanished from the exchange (cancelled by hand): detected, re-placed with a new generation, still protected', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    $first = $p->sl_algo_id;
    $s->fake->cancelProtective('BTCUSDT', $first, null);        // someone cancelled it on Binance
    $p = qtmRec($p);
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($p->protection_status)->toBe('PROTECTED')->and($p->sl_algo_id)->not->toBe($first)
        ->and($s->fake->liveAlgos())->toHaveCount(1)
        ->and(qtmEv($p, 'protection_lost'))->toBe(1)->and(qtmEv($p, 'protection_restored'))->toBe(1);
});

test('stop-loss vanished AND cannot be re-placed: emergency close, never a naked position', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    $s->fake->cancelProtective('BTCUSDT', $p->sl_algo_id, null);
    $s->fake->protectiveError = ManualExchangeException::rejected('nope', -4120);
    $p = qtmRec($p);
    expect($p->status)->toBe(P::CLOSED)->and($p->exit_reason)->toBe('emergency_protection_failed')->and($s->fake->amount)->toBe(0.0);
});

test('exchange unreadable: state is NOT guessed. After repeated failures EXCHANGE_STATE_UNKNOWN (stop untouched); recovery returns to OPEN_PROTECTED', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    $s->fake->readsFail = true;
    for ($i = 0; $i < 3; $i++) {
        $p = qtmRec($p);
    }
    expect($p->status)->toBe(P::EXCHANGE_STATE_UNKNOWN)->and($p->protection_status)->toBe('UNKNOWN')
        ->and($s->fake->liveAlgos())->toHaveCount(1)->and($s->fake->count('placeClose'))->toBe(0);

    $s->fake->readsFail = false;
    $p = qtmRec($p);
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($p->protection_status)->toBe('PROTECTED');
});

test('unknown state resolves to CLOSED when the exchange shows the stop already fired', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    $s->fake->readsFail = true;
    for ($i = 0; $i < 3; $i++) {
        $p = qtmRec($p);
    }
    $s->fake->readsFail = false;
    $s->fake->triggerAlgo('STOP_MARKET', 79200.0);
    $p = qtmRec($p);
    expect($p->status)->toBe(P::CLOSED)->and($p->exit_reason)->toBe('stop_loss');
});

test('crash between row creation and the entry order: reconciler marks ENTRY_FAILED, releases the stake, NEVER sends an entry', function () {
    // "intent recorded, order never sent"
    $s2 = ManualTradeScenario::make();
    $s2->fake->amount = 0.0;
    $row = P::create([
        'id' => (string) Str::uuid(), 'workspace_id' => $s2->workspace->id, 'integration_id' => $s2->integration->id, 'idempotency_key' => 'crash-before-order-1',
        'mode' => 'manual_sl_tp', 'environment' => 'testnet', 'symbol' => 'BTCUSDT', 'direction' => 'long', 'entry_side' => 'buy', 'position_mode' => 'one_way',
        'status' => P::ENTRY_PENDING, 'active_key' => $s2->workspace->id.':BTCUSDT', 'stake_amount' => 100, 'leverage' => 10, 'requested_quantity' => 0.012,
        'entry_reference_price' => 80000, 'sl_pct' => 1, 'entry_client_order_id' => 'qtm-e-crash1',
    ]);
    P::whereKey($row->id)->update(['updated_at' => now()->subMinutes(5)]);
    $row = qtmRec($row);
    expect($row->status)->toBe(P::ENTRY_FAILED)->and($row->active_key)->toBeNull()->and($s2->fake->count('placeEntry'))->toBe(0);
});

test('crash after the entry filled but before anything was recorded: reconciler finds the fill by client id and protects it', function () {
    $s = ManualTradeScenario::make();
    $row = P::create([
        'id' => (string) Str::uuid(), 'workspace_id' => $s->workspace->id, 'integration_id' => $s->integration->id, 'idempotency_key' => 'crash-after-order-1',
        'mode' => 'manual_sl_tp', 'environment' => 'testnet', 'symbol' => 'BTCUSDT', 'direction' => 'long', 'entry_side' => 'buy', 'position_mode' => 'one_way',
        'status' => P::ENTRY_PENDING, 'active_key' => $s->workspace->id.':BTCUSDT', 'stake_amount' => 100, 'leverage' => 10, 'requested_quantity' => 0.012,
        'entry_reference_price' => 80000, 'sl_pct' => 1, 'entry_client_order_id' => 'qtm-e-crash2',
    ]);
    // the order DID reach the exchange before the worker died
    $s->fake->placeEntry(['symbol' => 'BTCUSDT', 'side' => 'BUY', 'quantity' => '0.012', 'position_side' => null, 'client_order_id' => 'qtm-e-crash2']);
    P::whereKey($row->id)->update(['updated_at' => now()->subMinutes(5)]);

    $row = qtmRec($row);
    expect($row->status)->toBe(P::OPEN_PROTECTED)->and((float) $row->executed_quantity)->toBe(0.012)->and($row->sl_verified_at)->not->toBeNull()
        ->and($s->fake->count('placeEntry'))->toBe(1)                      // only the original, the reconciler never re-sent
        ->and($s->fake->liveAlgos())->toHaveCount(1);
});

test('crash during protection (PROTECTION_PENDING with a stop already on the exchange): resumes without placing a second stop', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    // pretend the worker died right after the SL was verified but before OPEN_PROTECTED was written
    P::whereKey($p->id)->update(['status' => P::PROTECTION_PENDING, 'protection_status' => 'PENDING']);
    $before = $s->fake->count('placeProtective');
    $p = qtmRec($p);
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($s->fake->count('placeProtective'))->toBe($before)->and($s->fake->liveAlgos())->toHaveCount(1);
});

test('DB lost the stop ids after a successful placement: recovered by the deterministic client id, no duplicate stop', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    $before = $s->fake->count('placeProtective');
    P::whereKey($p->id)->update(['status' => P::PROTECTION_PENDING, 'sl_algo_id' => null, 'sl_client_algo_id' => null, 'sl_status' => 'NONE', 'sl_verified_at' => null]);
    $p = qtmRec($p);
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and($p->sl_algo_id)->not->toBeEmpty()
        ->and($s->fake->count('placeProtective'))->toBe($before)->and($s->fake->liveAlgos())->toHaveCount(1);
});

test('quantity drift (another engine reduced/added on the same symbol) is flagged high, position left alone, still reconciled', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    $s->fake->amount = 0.009;
    $p = qtmRec($p);
    expect($p->status)->toBe(P::OPEN_PROTECTED)->and(qtmEv($p, 'quantity_drift'))->toBe(1)->and($s->fake->count('placeClose'))->toBe(0);
    qtmRec($p);
    expect(qtmEv($p, 'quantity_drift'))->toBe(1);                         // not spammed
});

test('direction flipped on the exchange by another engine: CRITICAL event, no auto-close of someone else\'s position', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    $s->fake->amount = -0.02;
    $p = qtmRec($p);
    expect(QuickTradeManualEvent::where('position_id', $p->id)->where('type', 'external_position_change')->where('severity', 'critical')->exists())->toBeTrue()
        ->and($s->fake->count('placeClose'))->toBe(0);
});

test('finalize is idempotent: reconciling a CLOSED position again never settles the wallet twice', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    $s->fake->mark = 80500.0;
    test()->postJson($s->url('/positions/'.$p->id.'/close'))->assertOk();
    $balance = (float) WalletAccount::firstOrFail()->balance;
    for ($i = 0; $i < 3; $i++) {
        app(ManualPositionReconciler::class)->run();
    }
    expect((float) WalletAccount::firstOrFail()->balance)->toBe($balance)->and(P::findOrFail($p->id)->status)->toBe(P::CLOSED);
});

test('terminal position with a leftover live protective order is swept (verified cancel)', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s);
    $s->fake->mark = 80500.0;
    test()->postJson($s->url('/positions/'.$p->id.'/close'))->assertOk();
    // simulate the cancel having failed earlier: DB still says WORKING, exchange order is alive
    $algoId = $p->refresh()->sl_algo_id;
    $s->fake->algos[$algoId]['status'] = 'NEW';
    P::whereKey($p->id)->update(['sl_status' => 'WORKING']);
    $r = app(ManualPositionReconciler::class)->run();
    expect($r['swept'])->toBeGreaterThanOrEqual(1)->and($s->fake->liveAlgos())->toBe([])->and($p->refresh()->sl_status)->toBe('CANCELLED');
});

test('refresh endpoint runs one reconciliation pass and returns live exchange numbers', function () {
    $s = ManualTradeScenario::make();
    $p = qtmOpenForRec($s, ['tp_pct' => 2]);
    $s->fake->mark = 80400.0;
    $r = test()->postJson($s->url('/positions/'.$p->id.'/refresh'))->assertOk();
    expect($r->json('live.mark_price'))->toBe(80400)->and($r->json('live.unrealized_pnl'))->toBe(4.8)->and($r->json('live.roe_pct'))->toBe(4.8)
        ->and($r->json('live.liquidation_price'))->toBeNull()->and($r->json('live.funding_rate'))->toBe(0.0001)
        ->and($r->json('events'))->not->toBeEmpty()->and($r->json('last_reconciled_at'))->not->toBeNull()
        ->and($r->json('has_expiry'))->toBeFalse();
});

test('console command: no-op with no active positions, reconciles when there are, and works with the feature flag OFF', function () {
    $s = ManualTradeScenario::make();
    $this->artisan('quick-trade-manual:reconcile')->assertExitCode(0);
    $p = qtmOpenForRec($s);
    $s->fake->triggerAlgo('STOP_MARKET', 79200.0);
    config()->set('quick_trade_manual.enabled', false);
    $this->artisan('quick-trade-manual:reconcile')->assertExitCode(0);
    expect($p->refresh()->status)->toBe(P::CLOSED);
});
