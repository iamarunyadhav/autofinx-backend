<?php

namespace App\Domain\QuickTradeManual;

use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGateway;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGatewayFactory;
use App\Models\Integration;
use App\Models\QuickTradeManualPosition as P;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings every non-terminal manual position back in line with the EXCHANGE, which is the
 * authority. Idempotent and restart-safe: it only reads state from the DB + exchange, takes
 * the same per-position lock the user close uses, and every state change is a guarded CAS.
 * It never sends a new ENTRY order (an unresolved entry is resolved by querying, never by
 * re-sending).
 */
class ManualPositionReconciler
{
    public function __construct(
        private readonly ManualPositionStateMachine $sm,
        private readonly ManualProtectionService $protection,
        private readonly ManualExitService $exits,
        private readonly ManualPositionService $positions,
        private readonly ManualExchangeGatewayFactory $gateways,
    ) {
    }

    /** @return array{checked: int, errors: int, swept: int} */
    public function run(int $limit = 50): array
    {
        $checked = $errors = $swept = 0;

        $rows = P::query()->whereNotIn('status', P::TERMINAL)->orderBy('updated_at')->limit($limit)->get();
        foreach ($rows as $p) {
            $checked++;
            try {
                $this->reconcileOne($p, 'reconciler');
            } catch (Throwable $e) {
                $errors++;
                Log::warning('quick_trade_manual_reconcile_failed', ['position_id' => $p->id, 'error' => $e->getMessage()]);
            }
        }

        // Terminal positions that still show a live protective order: remove it (verified).
        $leftovers = P::query()->whereIn('status', P::TERMINAL)
            ->where(fn ($q) => $q->where('sl_status', 'WORKING')->orWhere('tp_status', 'WORKING')->orWhere('sl_status', 'PLACED')->orWhere('tp_status', 'PLACED'))
            ->orderBy('updated_at')->limit(20)->get();
        foreach ($leftovers as $p) {
            try {
                $gw = $this->gatewayFor($p);
                $this->protection->cleanup($p, $gw);
                $swept++;
            } catch (Throwable $e) {
                Log::warning('quick_trade_manual_sweep_failed', ['position_id' => $p->id, 'error' => $e->getMessage()]);
            }
        }

        return ['checked' => $checked, 'errors' => $errors, 'swept' => $swept];
    }

    public function reconcileOne(P $p, string $source = 'reconciler'): P
    {
        $lock = Cache::lock('qtm:pos:'.$p->id, 55);
        if (! $lock->get()) {
            return $p->refresh();   // the user close / entry flow owns it right now
        }
        try {
            $p->refresh();
            if ($p->isTerminal()) {
                return $p;
            }
            $gw = $this->gatewayFor($p);

            return match ($p->status) {
                P::ENTRY_PENDING => $this->entryPending($p, $gw, $source),
                P::ENTRY_FILLED, P::PARTIALLY_FILLED, P::PROTECTION_PENDING => $this->positions->runProtection($p, $gw, $source),
                P::PROTECTION_FAILED => $this->exits->emergencyExit($p, $gw, $source, (string) ($p->last_error ?: 'protection_failed')),
                P::EMERGENCY_EXIT => $this->exits->emergencyExit($p, $gw, $source, (string) ($p->last_error ?: 'protection_failed')),
                P::OPEN_PROTECTED => $this->checkOpen($p, $gw, $source),
                P::CLOSING => $this->closingStale($p, $gw, $source),
                P::CLOSE_FAILED => $this->closeFailed($p, $gw, $source),
                P::RECONCILIATION => $this->exits->finalize($p, $gw, $source),
                P::EXCHANGE_STATE_UNKNOWN => $this->resolveUnknown($p, $gw, $source),
                default => $p,
            };
        } finally {
            $lock->release();
        }
    }

    private function entryPending(P $p, ManualExchangeGateway $gw, string $source): P
    {
        $stale = (int) config('quick_trade_manual.entry_pending_stale_seconds', 20);
        if ($p->updated_at && $p->updated_at->diffInSeconds(now()) < $stale) {
            return $p;   // the opener is probably still working
        }

        return $this->positions->continueEntry($p, $gw, null, $source);
    }

    private function checkOpen(P $p, ManualExchangeGateway $gw, string $source): P
    {
        try {
            $pos = $gw->position($p->symbol, $p->positionSide());
            $health = $this->protection->inspect($p, $gw);
        } catch (ManualExchangeException $e) {
            return $this->readFailed($p, $e);
        }
        if ($health['state'] === 'UNKNOWN') {
            return $this->readFailed($p, new ManualExchangeException('protective order state unreadable'));
        }

        $this->sm->patch($p, [
            'reconcile_failures' => 0,
            'last_reconciled_at' => now(),
            'exchange_liquidation_price' => $pos['liquidation_price'],
            'last_exchange_state' => ManualPositionStateMachine::jsonColumn(array_merge((array) ($p->last_exchange_state ?? []), [
                'amount' => $pos['amount'],
                'mark_price' => $pos['mark_price'],
                'unrealized_pnl' => $pos['unrealized_pnl'],
                'sl_status' => $health['sl']['status'] ?? null,
                'tp_status' => $health['tp']['status'] ?? null,
                'at' => now()->toIso8601String(),
            ])),
        ]);

        // 1) The exchange position is gone: SL / TP / liquidation / external closure.
        if (abs($pos['amount']) <= 0.0) {
            $attr = $this->exits->attributeExit($p, $gw);
            $moved = $this->sm->transition($p, P::RECONCILIATION, $source, [
                'exit_reason' => $attr['reason'],
                'closed_by' => 'exchange',
            ], $attr, 'exchange_exit_detected', in_array($attr['reason'], ['liquidation', 'external'], true) ? 'high' : 'info');

            return $moved ? $this->exits->finalize($p, $gw, $source) : $p;
        }

        // 2) Position is open. Direction/size sanity (another engine on the same symbol?).
        $expectedSign = $p->isLong() ? 1 : -1;
        if (($pos['amount'] > 0 ? 1 : -1) !== $expectedSign && $p->position_mode === 'one_way') {
            $this->sm->note($p, 'external_position_change', $source, ['exchange_amount' => $pos['amount'], 'expected_direction' => $p->direction, 'note' => 'Position direction on the exchange no longer matches. Another engine or manual order is acting on this symbol. NOT auto-closed.'], 'critical');
        } elseif (abs(abs($pos['amount']) - (float) $p->executed_quantity) > max(1e-9, (float) $p->executed_quantity * 0.001)) {
            $state = (array) ($p->last_exchange_state ?? []);
            if (($state['drift_amount'] ?? null) !== abs($pos['amount'])) {
                $this->sm->note($p, 'quantity_drift', $source, ['exchange_quantity' => abs($pos['amount']), 'recorded_quantity' => (float) $p->executed_quantity], 'high');
                $this->sm->patch($p, ['last_exchange_state' => ManualPositionStateMachine::jsonColumn(array_merge($state, ['drift_amount' => abs($pos['amount'])]))]);
            }
        }

        // 3) Protection must still exist and be working.
        if ($health['state'] === 'LOST') {
            $this->sm->note($p, 'protection_lost', $source, ['sl' => $health['sl']], 'critical');
            $this->sm->patch($p, ['protection_status' => P::PS_LOST, 'sl_status' => 'LOST']);
            $res = $this->protection->ensureLeg($p, $gw, 'sl', (float) $p->sl_trigger_price, $this->tick($gw, $p));
            if ($res['ok']) {
                $this->sm->patch($p, ['protection_status' => P::PS_PROTECTED, 'protected_at' => now()]);
                $this->sm->note($p, 'protection_restored', $source, [], 'high');

                return $p;
            }
            if ($this->sm->transition($p, P::PROTECTION_FAILED, $source, ['protection_status' => P::PS_FAILED, 'last_error' => mb_substr((string) $res['reason'], 0, 500)], ['reason' => $res['reason']], 'protection_failed', 'critical')) {
                return $this->exits->emergencyExit($p, $gw, $source, (string) $res['reason']);
            }

            return $p;
        }

        if ($p->tp_pct !== null && $p->tp_status === 'WORKING' && ($health['tp'] === null || ! in_array($health['tp']['status'], \App\Domain\QuickTradeManual\Exchange\BinanceAlgoApi::WORKING_STATUSES, true))) {
            $this->sm->patch($p, ['tp_status' => 'LOST']);
            $this->sm->note($p, 'take_profit_lost', $source, ['tp' => $health['tp']], 'high');
        }

        return $p;
    }

    private function closingStale(P $p, ManualExchangeGateway $gw, string $source): P
    {
        $stale = (int) config('quick_trade_manual.entry_pending_stale_seconds', 20);
        if ($p->updated_at && $p->updated_at->diffInSeconds(now()) < $stale) {
            return $p;
        }
        try {
            $pos = $gw->position($p->symbol, $p->positionSide());
            $order = $p->close_client_order_id ? $gw->queryOrder($p->symbol, $p->close_order_id ?: null, $p->close_client_order_id) : null;
        } catch (ManualExchangeException $e) {
            return $this->readFailed($p, $e);
        }
        if (abs($pos['amount']) <= 0.0) {
            $reason = $order !== null && $order['executed_qty'] > 0 ? 'manual' : $this->exits->attributeExit($p, $gw)['reason'];
            $moved = $this->sm->transition($p, P::RECONCILIATION, $source, [
                'exit_reason' => $reason,
                'close_order_id' => $order['order_id'] ?? $p->close_order_id,
            ], ['recovered_stale_closing' => true], 'closing_recovered_flat');

            return $moved ? $this->exits->finalize($p, $gw, $source) : $p;
        }
        $this->sm->transition($p, P::CLOSE_FAILED, $source, ['last_error' => 'close order did not flatten the position'], ['exchange_quantity' => abs($pos['amount'])], 'closing_stale_not_flat', 'high');

        return $p;
    }

    private function closeFailed(P $p, ManualExchangeGateway $gw, string $source): P
    {
        try {
            $pos = $gw->position($p->symbol, $p->positionSide());
            $health = $this->protection->inspect($p, $gw);
        } catch (ManualExchangeException $e) {
            return $this->readFailed($p, $e);
        }
        if (abs($pos['amount']) <= 0.0) {
            $attr = $this->exits->attributeExit($p, $gw);
            $moved = $this->sm->transition($p, P::RECONCILIATION, $source, ['exit_reason' => $attr['reason'], 'closed_by' => 'exchange'], $attr, 'close_failed_but_flat');

            return $moved ? $this->exits->finalize($p, $gw, $source) : $p;
        }
        if ($health['state'] === 'OK') {
            // The manual close failed but the exchange stop is verified: back to protected, user can retry.
            $this->sm->transition($p, P::OPEN_PROTECTED, $source, ['protection_status' => P::PS_PROTECTED, 'last_reconciled_at' => now()], ['note' => 'close failed; protection re-verified'], 'close_failed_position_still_protected');

            return $p;
        }

        return $this->exits->emergencyExit($p, $gw, $source, 'close_failed_and_protection_not_verified');
    }

    private function resolveUnknown(P $p, ManualExchangeGateway $gw, string $source): P
    {
        // Entry never resolved: only ever query, never re-send.
        if ($p->entry_order_id === null && (float) $p->executed_quantity <= 0.0) {
            return $this->positions->continueEntry($p, $gw, null, $source);
        }
        try {
            $pos = $gw->position($p->symbol, $p->positionSide());
            $health = $this->protection->inspect($p, $gw);
        } catch (ManualExchangeException $e) {
            return $this->readFailed($p, $e);
        }
        if (abs($pos['amount']) <= 0.0) {
            $attr = $this->exits->attributeExit($p, $gw);
            $moved = $this->sm->transition($p, P::RECONCILIATION, $source, ['exit_reason' => $attr['reason'], 'closed_by' => 'exchange'], $attr, 'unknown_resolved_flat');

            return $moved ? $this->exits->finalize($p, $gw, $source) : $p;
        }
        if ($health['state'] === 'OK' && $p->sl_verified_at !== null) {
            $this->sm->transition($p, P::OPEN_PROTECTED, $source, ['protection_status' => P::PS_PROTECTED, 'reconcile_failures' => 0, 'last_reconciled_at' => now()], [], 'unknown_resolved_protected');

            return $p;
        }

        return $this->positions->runProtection($p, $gw, $source);
    }

    private function readFailed(P $p, ManualExchangeException $e): P
    {
        $failures = (int) $p->reconcile_failures + 1;
        $this->sm->patch($p, ['reconcile_failures' => $failures, 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
        if ($failures >= (int) config('quick_trade_manual.reconcile_unknown_after_failures', 3) && $p->status !== P::EXCHANGE_STATE_UNKNOWN) {
            $this->sm->transition($p, P::EXCHANGE_STATE_UNKNOWN, 'reconciler', ['protection_status' => P::PS_UNKNOWN], ['unknown_from' => $p->status, 'error' => $e->getMessage(), 'note' => 'Exchange protection is NOT assumed lost; its state cannot be read from here.'], 'exchange_state_unknown', 'high');
        }

        return $p;
    }

    private function tick(ManualExchangeGateway $gw, P $p): float
    {
        try {
            return $gw->symbolRules($p->symbol)['tick_size'];
        } catch (ManualExchangeException) {
            return 0.01;
        }
    }

    private function gatewayFor(P $p): ManualExchangeGateway
    {
        $integration = Integration::query()->whereKey($p->integration_id)->first();
        if (! $integration) {
            throw new \RuntimeException('integration_missing for position '.$p->id);
        }

        return $this->gateways->make($integration);
    }
}
