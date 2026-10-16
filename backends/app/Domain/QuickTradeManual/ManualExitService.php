<?php

namespace App\Domain\QuickTradeManual;

use App\Domain\QuickTradeManual\Exchange\BinanceAlgoApi;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGateway;
use App\Domain\Trading\ExchangeExecutionCostReconciliationService;
use App\Domain\Trading\LiveWalletService;
use App\Models\QuickTradeManualPosition as P;
use Illuminate\Support\Facades\DB;

/**
 * Every way a manual position ends: manual close, emergency close, exchange-side exit
 * (SL/TP/liquidation/external), plus the money reconciliation that books the real result.
 *
 * Principles: reduce-only market closes (cannot reverse a position); an exit is "done" only when
 * the EXCHANGE shows the position flat; a close that cannot be confirmed is CLOSE_FAILED and
 * is retried, never reported as success; wallet settlement happens once, in the same DB
 * transaction as the CAS to the terminal state.
 */
class ManualExitService
{
    private const RECONCILE_RETRIES_BEFORE_ESTIMATE = 6;

    public function __construct(
        private readonly ManualPositionStateMachine $sm,
        private readonly ManualProtectionService $protection,
        private readonly LiveWalletService $wallets,
        private readonly ExchangeExecutionCostReconciliationService $costs,
    ) {
    }

    /**
     * @return array{state: string, order: ?array, reason: ?string}  state: flat | unconfirmed | rejected
     */
    public function closeAtExchange(P $p, ManualExchangeGateway $gw, string $clientOrderId): array
    {
        $ps = $p->positionSide();
        $qty = (float) $p->executed_quantity;

        try {
            $pos = $gw->position($p->symbol, $ps);
            if (abs($pos['amount']) <= 0.0) {
                return ['state' => 'flat', 'order' => null, 'reason' => null];
            }
            $qty = abs($pos['amount']);
        } catch (ManualExchangeException) {
            // Cannot read the position: still try to reduce with the quantity we know we opened.
        }
        if ($qty <= 0) {
            return ['state' => 'unconfirmed', 'order' => null, 'reason' => 'close_quantity_unknown'];
        }

        $order = null;
        try {
            $order = $gw->placeClose([
                'symbol' => $p->symbol,
                'side' => $p->closeSide(),
                'quantity' => self::formatQty($qty),
                'position_side' => $ps,
                'client_order_id' => $clientOrderId,
            ]);
        } catch (ManualExchangeException $e) {
            if (! $e->ambiguous) {
                // A reduce-only rejection (-2022) usually means there was nothing left to reduce, but the
                // code alone is never trusted: only the exchange position proves "already flat".
                if ($this->flatNow($p, $gw)) {
                    return ['state' => 'flat', 'order' => null, 'reason' => null];
                }

                return ['state' => 'rejected', 'order' => null, 'reason' => $e->getMessage()];
            }
            try {
                $order = $gw->queryOrder($p->symbol, null, $clientOrderId);
            } catch (ManualExchangeException) {
                $order = null;
            }
        }

        if ($order !== null && $order['executed_qty'] <= 0.0 && $order['avg_price'] <= 0.0 && $order['order_id'] !== '') {
            // Fill details can lag the placement response: re-query once.
            try {
                $order = $gw->queryOrder($p->symbol, $order['order_id'], null) ?? $order;
            } catch (ManualExchangeException) {
            }
        }

        // Only the exchange's own position counts as proof.
        try {
            $after = $gw->position($p->symbol, $ps);
        } catch (ManualExchangeException $e) {
            return ['state' => 'unconfirmed', 'order' => $order, 'reason' => 'position_unreadable_after_close: '.$e->getMessage()];
        }
        if (abs($after['amount']) <= 0.0) {
            return ['state' => 'flat', 'order' => $order, 'reason' => null];
        }

        return ['state' => 'unconfirmed', 'order' => $order, 'reason' => sprintf('position still open after close attempt (%.8f remaining)', abs($after['amount']))];
    }

    /** Immediate risk-reduction. Never claims success without the exchange showing the position flat. */
    public function emergencyExit(P $p, ManualExchangeGateway $gw, string $source, string $reason): P
    {
        if ($p->status !== P::EMERGENCY_EXIT) {
            if (! $this->sm->transition($p, P::EMERGENCY_EXIT, $source, [], ['reason' => $reason], 'emergency_exit_started', 'critical')) {
                return $p;   // somebody else owns this position now
            }
        }

        $attempt = (int) $p->close_attempts + 1;
        $clientId = sprintf('qtm-x-%s-%d', substr(str_replace('-', '', (string) $p->id), 0, 22), $attempt);
        $this->sm->patch($p, ['close_attempts' => $attempt, 'close_client_order_id' => $clientId, 'closing_at' => $p->closing_at ?? now()]);

        $res = $this->closeAtExchange($p, $gw, $clientId);

        if ($res['state'] === 'flat') {
            $this->protection->cleanup($p, $gw);
            $moved = $this->sm->transition($p, P::RECONCILIATION, $source, [
                'exit_reason' => str_starts_with($reason, 'stop_loss_not_inside') ? 'emergency_liquidation_risk' : 'emergency_protection_failed',
                'closed_by' => 'system',
                'emergency_close_confirmed' => true,
                'close_order_id' => $res['order']['order_id'] ?? null,
                'protection_status' => P::PS_FAILED,
            ], ['reason' => $reason, 'exchange_confirmed_flat' => true], 'emergency_exit_confirmed', 'high');

            return $moved ? $this->finalize($p, $gw, $source) : $p;
        }

        $this->sm->transition($p, P::CLOSE_FAILED, $source, [
            'last_error' => mb_substr((string) $res['reason'], 0, 500),
            'emergency_close_confirmed' => false,
        ], ['reason' => $reason, 'close' => $res['state'], 'detail' => $res['reason'], 'position_may_be_unprotected' => true], 'emergency_exit_not_confirmed', $p->critical_alert ? 'high' : 'critical');

        return $p;
    }

    /** User-initiated close. Caller has validated membership and holds the per-position lock. */
    public function manualClose(P $p, ManualExchangeGateway $gw, ?string $userId): P
    {
        $attempt = (int) $p->close_attempts + 1;
        $clientId = sprintf('qtm-c-%s-%d', substr(str_replace('-', '', (string) $p->id), 0, 22), $attempt);

        $won = $this->sm->transition($p, P::CLOSING, 'api', [
            'close_attempts' => $attempt,
            'close_client_order_id' => $clientId,
            'closing_at' => now(),
            'closed_by' => 'user',
        ], ['user_id' => $userId, 'attempt' => $attempt], 'manual_close_requested');
        if (! $won) {
            return $p;   // duplicate click / reconciler or SL got there first: no second order
        }

        $res = $this->closeAtExchange($p, $gw, $clientId);

        if ($res['state'] === 'flat') {
            $viaUs = $res['order'] !== null;
            $updates = ['close_order_id' => $res['order']['order_id'] ?? null];
            if ($viaUs) {
                $updates['exit_reason'] = 'manual';
            } else {
                // Flat before our order: the exchange (SL/TP/liquidation) won the race.
                $attr = $this->attributeExit($p, $gw);
                $updates['exit_reason'] = $attr['reason'];
                $updates['closed_by'] = 'exchange';
            }
            $this->protection->cleanup($p, $gw);
            $moved = $this->sm->transition($p, P::RECONCILIATION, 'api', $updates, ['via_manual_order' => $viaUs], $viaUs ? 'manual_close_filled' : 'manual_close_lost_race_position_already_flat');

            return $moved ? $this->finalize($p, $gw, 'api') : $p;
        }

        $this->sm->transition($p, P::CLOSE_FAILED, 'api', ['last_error' => mb_substr((string) $res['reason'], 0, 500)], [
            'close' => $res['state'],
            'detail' => $res['reason'],
            'note' => $p->noStopLoss() ? 'no stop-loss on this position (explicit opt-in): close it manually again' : 'exchange stop-loss remains in place until the reconciler verifies it',
        ], 'manual_close_failed', 'high');

        return $p;
    }

    /**
     * Why is a position that we did not close flat on the exchange?
     * @return array{reason: string, basis: string}
     */
    public function attributeExit(P $p, ManualExchangeGateway $gw): array
    {
        $legStatus = function (string $leg) use ($p, $gw): ?string {
            if ($leg === 'sl' && $p->noStopLoss()) {
                return null;   // no stop was ever placed: nothing to look up (and never attribute an exit to one)
            }
            try {
                $q = $gw->queryProtective($p->symbol, $p->{$leg.'_algo_id'} ?: null, $p->{$leg.'_client_algo_id'} ?: null);
            } catch (ManualExchangeException) {
                return null;
            }

            return $q['status'] ?? null;
        };

        if (in_array($legStatus('sl'), BinanceAlgoApi::TRIGGERED_STATUSES, true)) {
            return ['reason' => 'stop_loss', 'basis' => 'exchange_algo_status'];
        }
        if ($p->tp_pct !== null && in_array($legStatus('tp'), BinanceAlgoApi::TRIGGERED_STATUSES, true)) {
            return ['reason' => 'take_profit', 'basis' => 'exchange_algo_status'];
        }
        if ($p->trailing_pct !== null && in_array($legStatus('trailing'), BinanceAlgoApi::TRIGGERED_STATUSES, true)) {
            return ['reason' => 'trailing_stop', 'basis' => 'exchange_algo_status'];
        }
        $since = $p->opened_at ? $p->opened_at->getTimestamp() * 1000 : 0;
        if ($gw->liquidationSince($p->symbol, $since) === true) {
            return ['reason' => 'liquidation', 'basis' => 'exchange_force_orders'];
        }

        return ['reason' => 'external', 'basis' => 'no_exchange_evidence_of_sl_tp_or_liquidation'];
    }

    /**
     * RECONCILIATION -> CLOSED | LIQUIDATED with the exchange's real fills, fees and funding.
     * Idempotent: only the caller that wins the CAS settles the wallet.
     */
    public function finalize(P $p, ManualExchangeGateway $gw, string $source): P
    {
        if ($p->status !== P::RECONCILIATION) {
            return $p;
        }
        $this->protection->cleanup($p, $gw);

        $qtyOpened = (float) $p->executed_quantity;
        $entryPrice = (float) $p->entry_price;
        $sign = $p->isLong() ? 1 : -1;
        $openedMs = $p->opened_at ? $p->opened_at->getTimestamp() * 1000 : 0;

        $entryFills = $p->entry_order_id ? $gw->orderFills($p->symbol, (string) $p->entry_order_id) : null;

        $exitFills = null;
        if ($p->close_order_id) {
            $exitFills = $gw->orderFills($p->symbol, (string) $p->close_order_id);
        } else {
            $trades = $gw->tradesSince($p->symbol, max(0, $openedMs - 5000));
            if ($trades !== null) {
                $q = $notional = $comm = $pnl = 0.0;
                foreach ($trades as $t) {
                    if ($t['side'] === $p->closeSide() && $t['order_id'] !== (string) $p->entry_order_id && $t['time_ms'] >= $openedMs - 5000) {
                        $q += $t['quantity'];
                        $notional += $t['quantity'] * $t['price'];
                        $comm += $t['commission'];
                        $pnl += $t['realized_pnl'];
                    }
                }
                // Only accept a complete picture of the exit (all opened quantity accounted for).
                if ($q > 0 && $qtyOpened > 0 && $q >= $qtyOpened * 0.999) {
                    $exitFills = ['commission' => round($comm, 8), 'realized_pnl' => round($pnl, 8), 'quantity' => $q, 'avg_price' => $notional / $q, 'fills' => count($trades)];
                }
            }
        }

        $estimated = false;
        if ($exitFills === null) {
            $failures = (int) $p->reconcile_failures + 1;
            if ($failures < self::RECONCILE_RETRIES_BEFORE_ESTIMATE) {
                $this->sm->patch($p, ['reconcile_failures' => $failures, 'last_error' => 'exit_fills_not_available_yet'], [P::RECONCILIATION]);

                return $p;   // stay RECONCILIATION; next tick retries with the exchange as the authority
            }
            $estimated = true;
            $exitPrice = $p->exit_reason === 'stop_loss' ? (float) $p->sl_trigger_price
                : ($p->exit_reason === 'take_profit' ? (float) $p->tp_trigger_price : $entryPrice);
            if ($exitPrice <= 0) {
                $exitPrice = $entryPrice;
            }
        } else {
            $exitPrice = (float) $exitFills['avg_price'];
        }

        $qty = $exitFills !== null ? min((float) $exitFills['quantity'], $qtyOpened > 0 ? $qtyOpened : (float) $exitFills['quantity']) : $qtyOpened;
        $gross = round(($exitPrice - $entryPrice) * $qty * $sign, 8);

        $rate = (float) config('quick_trade_manual.taker_fee_rate', 0.0005);
        $income = $gw->fundingIncome($p->symbol, $openedMs, (int) floor(microtime(true) * 1000));
        [$entryFee, $exitFee, $funding, , , , $source2] = $this->costs->resolveFees(
            $entryFills !== null ? (float) $entryFills['commission'] : null,
            $exitFills !== null ? (float) $exitFills['commission'] : null,
            $income !== null ? round(-1 * $income, 8) : null,
            round($entryPrice * $qty * $rate, 8),
            round($exitPrice * $qty * $rate, 8),
        );
        $net = $this->costs->netPnl($gross, $entryFee + $exitFee, $funding);
        $final = $p->exit_reason === 'liquidation' ? P::LIQUIDATED : P::CLOSED;

        DB::transaction(function () use ($p, $final, $source, $exitPrice, $entryFee, $exitFee, $funding, $gross, $net, $estimated, $source2): void {
            $ok = $this->sm->transition($p, $final, $source, [
                'exit_price' => $exitPrice,
                'entry_fee' => $entryFee,
                'exit_fee' => $exitFee,
                'funding_fee' => $funding,
                'gross_pnl' => $gross,
                'net_pnl' => $net,
                'pnl_source' => $estimated ? 'estimated' : $source2,
                'closed_at' => now(),
                'wallet_settled_at' => now(),
                'closed_by' => $p->closed_by ?? 'exchange',
                'last_error' => null,
            ], ['exit_reason' => $p->exit_reason, 'net_pnl' => $net, 'gross_pnl' => $gross, 'pnl_source' => $estimated ? 'estimated' : $source2], 'position_finalized', $estimated ? 'high' : 'info');

            if ($ok && $p->wallet_account_id) {
                $this->wallets->settleReservation((string) $p->workspace_id, (string) $p->wallet_account_id, (float) $p->stake_amount, $net, [
                    'quick_trade_manual_position_id' => $p->id,
                    'source' => 'quick_trade_manual_sl_tp',
                    'symbol' => $p->symbol,
                    'integration_id' => $p->integration_id,
                    'trace_id' => (string) $p->id,
                ]);
            }
        });

        return $p->refresh();
    }

    public static function formatQty(float $qty): string
    {
        $s = rtrim(rtrim(number_format($qty, 10, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }

    private function flatNow(P $p, ManualExchangeGateway $gw): bool
    {
        try {
            return abs($gw->position($p->symbol, $p->positionSide())['amount']) <= 0.0;
        } catch (ManualExchangeException) {
            return false;
        }
    }
}
