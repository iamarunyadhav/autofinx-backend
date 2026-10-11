<?php

namespace App\Domain\QuickTradeManual;

use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGatewayFactory;
use App\Models\Integration;
use App\Models\QuickTradeManualPosition as P;

/** API shape for a manual position. Whitelisted fields only; numbers are real stored/exchange values, never invented. */
class ManualPositionPresenter
{
    public function __construct(private readonly ManualExchangeGatewayFactory $gateways)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(P $p, ?array $live = null, bool $withEvents = false): array
    {
        $f = fn ($v) => $v === null ? null : (float) $v;
        $out = [
            'id' => $p->id,
            'mode' => $p->mode,
            'status' => $p->status,
            'environment' => $p->environment,
            'symbol' => $p->symbol,
            'direction' => $p->direction,
            'position_mode' => $p->position_mode,
            'margin_type' => $p->margin_type,
            'stake_amount' => $f($p->stake_amount),
            'leverage' => $f($p->leverage),
            'requested_quantity' => $f($p->requested_quantity),
            'executed_quantity' => $f($p->executed_quantity),
            'entry_reference_price' => $f($p->entry_reference_price),
            'entry_price' => $f($p->entry_price),
            'entry_slippage_pct' => $f($p->entry_slippage_pct),
            'sl_pct' => $f($p->sl_pct),
            'sl_basis' => 'price_movement',
            'sl_trigger_price' => $f($p->sl_trigger_price),
            'tp_pct' => $f($p->tp_pct),
            'tp_trigger_price' => $f($p->tp_trigger_price),
            'trailing_pct' => $f($p->trailing_pct),
            'trailing_activation_pct' => $f($p->trailing_activation_pct),
            'trailing_activation_price' => $f($p->trailing_activation_price),
            'trailing_status' => $p->trailing_status,
            'trailing_algo_id' => $p->trailing_algo_id,
            'trailing_verified_at' => $p->trailing_verified_at?->toIso8601String(),
            'estimated_liquidation_price' => $f($p->estimated_liquidation_price),
            'exchange_liquidation_price' => $f($p->exchange_liquidation_price),
            'protection_status' => $p->protection_status,
            'sl_status' => $p->sl_status,
            'tp_status' => $p->tp_status,
            'sl_algo_id' => $p->sl_algo_id,
            'tp_algo_id' => $p->tp_algo_id,
            'sl_verified_at' => $p->sl_verified_at?->toIso8601String(),
            'entry_order_id' => $p->entry_order_id,
            'close_order_id' => $p->close_order_id,
            'exit_reason' => $p->exit_reason,
            'closed_by' => $p->closed_by,
            'exit_price' => $f($p->exit_price),
            'entry_fee' => $f($p->entry_fee),
            'exit_fee' => $f($p->exit_fee),
            'funding_fee' => $f($p->funding_fee),
            'gross_pnl' => $f($p->gross_pnl),
            'net_pnl' => $f($p->net_pnl),
            'pnl_source' => $p->pnl_source,
            'critical_alert' => (bool) $p->critical_alert,
            'emergency_close_confirmed' => (bool) $p->emergency_close_confirmed,
            'last_error' => $p->last_error,
            'opened_at' => $p->opened_at?->toIso8601String(),
            'protected_at' => $p->protected_at?->toIso8601String(),
            'closed_at' => $p->closed_at?->toIso8601String(),
            'last_reconciled_at' => $p->last_reconciled_at?->toIso8601String(),
            'created_at' => $p->created_at?->toIso8601String(),
            'age_seconds' => $p->opened_at ? (int) max(0, $p->opened_at->diffInSeconds(now())) : null,
            'is_terminal' => $p->isTerminal(),
            'can_close' => in_array($p->status, [P::OPEN_PROTECTED, P::CLOSE_FAILED], true),
            'outcome' => $this->outcome($p),
            'has_expiry' => false,
            'live' => $live,
        ];
        if ($withEvents) {
            $out['events'] = $p->events()->reorder('seq', 'desc')->limit(40)->get()->map(fn ($e) => [
                'type' => $e->type,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'source' => $e->source,
                'severity' => $e->severity,
                'payload' => $e->payload,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all();
        }

        return $out;
    }

    /** Best-effort live exchange numbers. Null + error string on failure (never fabricated). */
    public function live(P $p): ?array
    {
        if ($p->isTerminal()) {
            return null;
        }
        try {
            $integration = Integration::query()->whereKey($p->integration_id)->first();
            if (! $integration) {
                return ['error' => 'integration_missing'];
            }
            $gw = $this->gateways->make($integration);
            $mark = $gw->markPrice($p->symbol);
            $pos = $gw->position($p->symbol, $p->positionSide());
        } catch (\Throwable $e) {
            return ['error' => mb_substr($e instanceof ManualExchangeException ? $e->getMessage() : 'exchange_unavailable', 0, 200)];
        }

        $qty = abs($pos['amount']) > 0 ? abs($pos['amount']) : (float) $p->executed_quantity;
        $entry = (float) $p->entry_price;
        $sign = $p->isLong() ? 1 : -1;
        $unrealized = abs($pos['amount']) > 0 ? $pos['unrealized_pnl'] : ($entry > 0 ? ($mark['mark_price'] - $entry) * $qty * $sign : null);
        $rate = (float) config('quick_trade_manual.taker_fee_rate', 0.0005);
        $entryFee = $p->entry_fee !== null ? (float) $p->entry_fee : $entry * $qty * $rate;
        $exitFee = $mark['mark_price'] * $qty * $rate;
        $stake = (float) $p->stake_amount;

        return [
            'error' => null,
            'mark_price' => $mark['mark_price'],
            'exchange_quantity' => abs($pos['amount']),
            'exchange_entry_price' => $pos['entry_price'] ?: null,
            'unrealized_pnl' => $unrealized,
            'estimated_net_pnl' => $unrealized !== null ? round($unrealized - $entryFee - $exitFee, 8) : null,
            'roe_pct' => ($unrealized !== null && $stake > 0) ? round($unrealized / $stake * 100, 4) : null,
            'liquidation_price' => $pos['liquidation_price'],
            'funding_rate' => $mark['funding_rate'],
            'next_funding_ms' => $mark['next_funding_ms'],
            'exchange_margin_type' => $pos['margin_type'],
            'exchange_leverage' => $pos['leverage'],
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    private function outcome(P $p): string
    {
        return match (true) {
            $p->status === P::OPEN_PROTECTED => 'open_protected',
            $p->status === P::ENTRY_FAILED => 'entry_failed',
            $p->status === P::EXCHANGE_STATE_UNKNOWN => 'state_unknown_reconciling',
            $p->status === P::CLOSE_FAILED && ! $p->emergency_close_confirmed && $p->critical_alert => 'close_unconfirmed_position_may_be_unprotected',
            $p->emergency_close_confirmed => 'protection_failed_emergency_closed',
            $p->isTerminal() => strtolower($p->status),
            default => strtolower($p->status),
        };
    }
}
