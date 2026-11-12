<?php

namespace App\Domain\QuickTradeManual;

use App\Domain\Ops\TradingEnvironmentGuard;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGateway;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGatewayFactory;
use App\Domain\Risk\WorkspaceLiveRiskService;
use App\Domain\Trading\LiveWalletService;
use App\Domain\Trading\TradingModuleSettingsService;
use App\Models\Integration;
use App\Models\QuickTrade;
use App\Models\QuickTradeManualPosition as P;
use App\Models\QuickTradeManualProtectionPair;
use App\Support\RiskGuardService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Entry orchestration for Manual SL/TP positions: validate -> reserve -> persist intent ->
 * market entry -> resolve the REAL fill -> exchange-native protection (verified) -> OPEN_PROTECTED,
 * or emergency close. Also the manual close entry point. See docs/quick-trade-manual/DESIGN.md.
 */
class ManualPositionService
{
    public function __construct(
        private readonly ManualFeatureGate $gate,
        private readonly ManualPreviewService $preview,
        private readonly ManualPositionStateMachine $sm,
        private readonly ManualProtectionService $protection,
        private readonly ManualExitService $exits,
        private readonly ManualExchangeGatewayFactory $gateways,
        private readonly TradingEnvironmentGuard $modeGuard,
        private readonly TradingModuleSettingsService $settings,
        private readonly LiveWalletService $wallets,
        private readonly RiskGuardService $riskGuard,
        private readonly WorkspaceLiveRiskService $liveRisk,
    ) {
    }

    /** @return array<string, mixed> */
    public function previewFor(string $workspaceId, array $input): array
    {
        $this->gate->assertEnabled($workspaceId);
        $in = $this->preview->normalizeInput($input, false);
        $integration = $this->preview->resolveIntegration($workspaceId, $input['integration_id'] ?? null);
        $gw = $this->gateways->make($integration);

        $est = $this->preview->compute($gw, $in);
        $est['integration_id'] = $integration->id;
        $est['live_gate'] = $this->liveGateReason($workspaceId);

        return $est;
    }

    public function open(string $workspaceId, ?string $userId, array $input): P
    {
        $this->gate->assertEnabled($workspaceId);

        $idem = trim((string) ($input['idempotency_key'] ?? ''));
        if (strlen($idem) < 8 || strlen($idem) > 120) {
            throw new RuntimeException('idempotency_key_required: Send a unique idempotency_key (8-120 chars) per submit attempt.');
        }
        // Replay of the same submit returns the same row: never a second order.
        $existing = P::query()->where('workspace_id', $workspaceId)->where('idempotency_key', $idem)->first();
        if ($existing) {
            return $existing;
        }

        $in = $this->preview->normalizeInput($input);
        $liveGate = $this->liveGateReason($workspaceId);
        if ($liveGate !== null) {
            throw new RuntimeException('live_not_ready: '.$liveGate);
        }
        $integration = $this->preview->resolveIntegration($workspaceId, $input['integration_id'] ?? null);

        $this->riskGuard->enforceOrFail($workspaceId);

        $lock = Cache::lock('qtm:open:'.$workspaceId.':'.$in['symbol'], 150);
        if (! $lock->get()) {
            throw new RuntimeException('open_in_progress: Another Manual SL/TP open is in progress for this symbol.');
        }

        try {
            $existing = P::query()->where('workspace_id', $workspaceId)->where('idempotency_key', $idem)->first();
            if ($existing) {
                return $existing;
            }

            $this->assertNoConflicts($workspaceId, $in['symbol']);

            $gw = $this->gateways->make($integration);
            $est = $this->preview->compute($gw, $in);
            if (! $est['ok']) {
                $first = $est['errors'][0];
                throw new RuntimeException($first['code'].': '.$first['message']);
            }

            $ref = isset($input['reference_price']) && is_numeric($input['reference_price']) ? (float) $input['reference_price'] : null;
            if ($ref !== null && $ref > 0) {
                $moved = abs($est['reference_price'] - $ref) / $ref * 100;
                $tol = (float) config('quick_trade_manual.price_tolerance_pct', 0.3);
                if ($moved > $tol) {
                    throw new RuntimeException(sprintf('price_moved: Mark price moved %.3f%% since you confirmed (%.8f -> %.8f, tolerance %.2f%%). Review and confirm again.', $moved, $ref, $est['reference_price'], $tol));
                }
            }

            $manualNotional = (float) P::query()->where('workspace_id', $workspaceId)->whereNotIn('status', P::TERMINAL)
                ->get(['stake_amount', 'leverage'])->sum(fn ($r) => (float) $r->stake_amount * (float) $r->leverage);
            $this->liveRisk->assertQuickTradeExposureAllowed($workspaceId, $est['notional'] + $manualNotional);

            // Leverage is an account/symbol setting on the exchange: set it and read it back.
            try {
                $gw->setLeverage($in['symbol'], (int) round($in['leverage']));
                $hedge = $est['position_mode'] === 'hedge';
                $after = $gw->position($in['symbol'], $hedge ? ($in['direction'] === 'long' ? 'LONG' : 'SHORT') : null);
            } catch (ManualExchangeException $e) {
                throw new RuntimeException('exchange_unavailable: '.$e->getMessage());
            }
            if ($after['leverage'] !== null && abs($after['leverage'] - $in['leverage']) > 0.01) {
                throw new RuntimeException(sprintf('leverage_mismatch: Exchange leverage is %sx, expected %sx. Refused.', $after['leverage'], $in['leverage']));
            }

            $account = $this->wallets->reserveStake($workspaceId, $integration, $in['stake'], null, 'USDT', [
                'source' => 'quick_trade_manual_sl_tp',
                'symbol' => $in['symbol'],
                'trace_id' => $idem,
            ]);

            $id = (string) Str::uuid();
            $idCompact = substr(str_replace('-', '', $id), 0, 24);
            try {
                $p = P::create([
                    'id' => $id,
                    'workspace_id' => $workspaceId,
                    'created_by' => $userId,
                    'integration_id' => $integration->id,
                    'wallet_account_id' => $account->id,
                    'idempotency_key' => $idem,
                    'mode' => P::MODE,
                    'environment' => $gw->environment(),
                    'symbol' => $in['symbol'],
                    'direction' => $in['direction'],
                    'entry_side' => $in['direction'] === 'long' ? 'buy' : 'sell',
                    'position_mode' => $est['position_mode'],
                    'margin_type' => $est['margin_type'],
                    'status' => P::ENTRY_PENDING,
                    'active_key' => $workspaceId.':'.$in['symbol'],
                    'protection_status' => P::PS_NONE,
                    'stake_amount' => $in['stake'],
                    'leverage' => $in['leverage'],
                    'requested_quantity' => $est['quantity'],
                    'entry_reference_price' => $est['reference_price'],
                    'estimated_liquidation_price' => $est['estimated_liquidation_price'],
                    'sl_pct' => $in['no_stop_loss'] ? 0 : $in['sl_pct'],   // 0 = explicit no-stop opt-in (column is NOT NULL); see P::noStopLoss()
                    'sl_trigger_price' => $est['sl_trigger_price'],
                    'tp_pct' => $in['tp_pct'],
                    'tp_trigger_price' => $est['take_profit']['trigger_price'] ?? null,
                    'trailing_pct' => isset($in['trailing_pct']) ? (float) $in['trailing_pct'] : null,
                    'trailing_activation_pct' => isset($in['trailing_activation_pct']) ? (float) $in['trailing_activation_pct'] : null,
                    'entry_client_order_id' => 'qtm-e-'.$idCompact,
                ]);
                if ($in['protection_pairs'] !== [] && ! Schema::hasTable('quick_trade_manual_protection_pairs')) {
                    throw new RuntimeException('protection_pairs_migration_pending: Apply the Manual TP/SL pair migration before using staged protection.');
                }
                foreach ($in['protection_pairs'] as $pair) {
                    QuickTradeManualProtectionPair::create([
                        'id' => (string) Str::uuid(),
                        'position_id' => $p->id,
                        'workspace_id' => $workspaceId,
                        'level' => $pair['level'],
                        'tp_mode' => $pair['tp_mode'],
                        'tp_value' => $pair['tp_value'],
                        'sl_mode' => $pair['sl_mode'],
                        'sl_input_mode' => $pair['sl_input_mode'],
                        'sl_value' => $pair['sl_value'],
                    ]);
                }
            } catch (UniqueConstraintViolationException $e) {
                // Lost a race for the (workspace, symbol) slot or the idempotency key: nothing was sent to the exchange.
                $this->wallets->cancelReservation($workspaceId, $account->id, $in['stake'], ['trace_id' => $idem, 'symbol' => $in['symbol']]);
                $dupe = P::query()->where('workspace_id', $workspaceId)->where('idempotency_key', $idem)->first();
                if ($dupe) {
                    return $dupe;
                }
                throw new RuntimeException('duplicate_manual_position: A Manual SL/TP position is already active on this symbol.');
            }
            $this->sm->note($p, 'entry_intent_recorded', 'api', ['quantity' => $est['quantity'], 'reference_price' => $est['reference_price'], 'sl_pct' => $in['sl_pct'], 'no_stop_loss' => $in['no_stop_loss'], 'no_stop_loss_acknowledged' => $in['no_stop_loss'] ? true : null, 'acknowledged_by' => $in['no_stop_loss'] ? $userId : null, 'tp_pct' => $in['tp_pct'], 'trailing_pct' => $in['trailing_pct'] ?? null, 'trailing_activation_pct' => $in['trailing_activation_pct'] ?? null, 'sl_basis' => 'price_movement']);
        } finally {
            $lock->release();
        }

        // From here on the position row exists. Take the per-position lock for the whole entry+protection.
        $posLock = Cache::lock('qtm:pos:'.$p->id, 150);
        $posLock->get();
        try {
            return $this->executeEntry($p, $gw, 'api');
        } finally {
            $posLock->release();
        }
    }

    /** Market entry -> real fill -> protection. Also used by the reconciler for crash recovery (it passes order=null and never re-sends). */
    public function executeEntry(P $p, ManualExchangeGateway $gw, string $source): P
    {
        $order = null;
        if ($p->status === P::ENTRY_PENDING && $p->entry_order_id === null && $source === 'api') {
            try {
                $order = $gw->placeEntry([
                    'symbol' => $p->symbol,
                    'side' => $p->entry_side,
                    'quantity' => ManualExitService::formatQty((float) $p->requested_quantity),
                    'position_side' => $p->positionSide(),
                    'client_order_id' => (string) $p->entry_client_order_id,
                ]);
            } catch (ManualExchangeException $e) {
                if (! $e->ambiguous) {
                    return $this->failEntry($p, $source, $e->getMessage(), true);
                }
                $this->sm->note($p, 'entry_outcome_unknown', $source, ['error' => $e->getMessage()], 'high');
            }
        }

        return $this->continueEntry($p, $gw, $order, $source);
    }

    /** Resolve what the exchange really did with the entry, then protect. Never places an order. */
    public function continueEntry(P $p, ManualExchangeGateway $gw, ?array $order, string $source): P
    {
        $fill = $this->resolveEntryFill($p, $gw, $order);

        if ($fill['state'] === 'none') {
            return $this->failEntry($p, $source, 'Entry order did not fill (no executed quantity, no exchange position).', false);
        }
        if ($fill['state'] === 'unknown') {
            if ($p->status !== P::EXCHANGE_STATE_UNKNOWN) {
                $this->sm->transition($p, P::EXCHANGE_STATE_UNKNOWN, $source, ['last_error' => 'entry_outcome_unknown'], ['unknown_from' => $p->status], 'entry_state_unknown', 'critical');
            }

            return $p;
        }

        $executed = $fill['qty'];
        $avg = $fill['avg'];
        $requested = (float) $p->requested_quantity;
        $partial = $requested > 0 && $executed < $requested * 0.999;
        $slip = (float) $p->entry_reference_price > 0
            ? round(($p->isLong() ? $avg - (float) $p->entry_reference_price : (float) $p->entry_reference_price - $avg) / (float) $p->entry_reference_price * 100, 6)
            : null;

        $moved = $this->sm->transition($p, $partial ? P::PARTIALLY_FILLED : P::ENTRY_FILLED, $source, [
            'executed_quantity' => $executed,
            'entry_price' => $avg,
            'entry_order_id' => $fill['order_id'] !== '' ? $fill['order_id'] : $p->entry_order_id,
            'entry_slippage_pct' => $slip,
            'opened_at' => $p->opened_at ?? now(),
            'sl_trigger_price' => null,      // recomputed from the ACTUAL fill in the protection step
            'tp_trigger_price' => null,
        ], ['executed_quantity' => $executed, 'requested_quantity' => $requested, 'avg_price' => $avg, 'basis' => $fill['basis']], $partial ? 'entry_partially_filled' : 'entry_filled');
        if (! $moved && ! in_array($p->status, [P::ENTRY_FILLED, P::PARTIALLY_FILLED], true)) {
            return $p;
        }

        if (Schema::hasTable('quick_trade_manual_protection_pairs')) $this->resolveProtectionPairs($p->refresh(), $avg);

        return $this->runProtection($p, $gw, $source);
    }

    /** Resolve all pair prices from the actual fill; preview prices are never used for protection. */
    private function resolveProtectionPairs(P $p, float $entry): void
    {
        if ($entry <= 0) return;
        foreach ($p->protectionPairs as $pair) {
            $tp = $pair->tp_mode === 'price'
                ? (float) $pair->tp_value
                : ($p->isLong() ? $entry * (1 + (float) $pair->tp_value / 100) : $entry * (1 - (float) $pair->tp_value / 100));
            $sl = null;
            if ($pair->sl_mode === 'fixed') {
                $sl = $pair->sl_input_mode === 'price'
                    ? (float) $pair->sl_value
                    : ($p->isLong() ? $tp * (1 - (float) $pair->sl_value / 100) : $tp * (1 + (float) $pair->sl_value / 100));
            } elseif ($pair->sl_input_mode === 'distance') {
                $sl = $p->isLong() ? $tp - (float) $pair->sl_value : $tp + (float) $pair->sl_value;
            } else {
                $sl = $p->isLong() ? $tp * (1 - (float) $pair->sl_value / 100) : $tp * (1 + (float) $pair->sl_value / 100);
            }
            if (($p->isLong() && ($tp <= $entry || $sl >= $tp)) || ($p->isLong() === false && ($tp >= $entry || $sl <= $tp))) {
                $pair->update(['status' => QuickTradeManualProtectionPair::CANCELLED]);
                $this->sm->note($p, 'protection_pair_rejected_after_fill', 'system', ['level' => $pair->level, 'entry_price' => $entry, 'tp_price' => $tp, 'sl_price' => $sl], 'high');
                continue;
            }
            $pair->update([
                'tp_price' => round($tp, 10),
                'sl_price' => round($sl, 10),
                'active_stop_price' => round($sl, 10),
                'peak_price' => $p->isLong() ? $tp : null,
                'trough_price' => $p->isLong() ? null : $tp,
            ]);
        }
    }

    /** ENTRY_FILLED/PARTIALLY_FILLED/UNKNOWN/PROTECTION_PENDING -> OPEN_PROTECTED, or PROTECTION_FAILED -> EMERGENCY_EXIT. */
    public function runProtection(P $p, ManualExchangeGateway $gw, string $source): P
    {
        if ($p->status !== P::PROTECTION_PENDING) {
            if (! $this->sm->transition($p, P::PROTECTION_PENDING, $source, ['protection_status' => P::PS_PENDING], [], 'protection_started')) {
                return $p;
            }
        }

        $res = $this->protection->protect($p, $gw);
        if ($res['ok'] && $p->noStopLoss()) {
            // Deliberate no-stop trade: open and managed, but NOT exchange-protected. Say so in every field.
            $this->sm->transition($p, P::OPEN_PROTECTED, $source, [
                'protection_status' => P::PS_NO_STOP,
                'protected_at' => null,
                'last_reconciled_at' => now(),
                'last_error' => null,
            ], ['no_stop_loss' => true, 'tp_algo_id' => $p->tp_algo_id, 'note' => 'Opened by explicit opt-in without a stop-loss. Close manually.'], 'open_without_stop_loss', 'high');

            return $p;
        }
        if ($res['ok']) {
            $this->sm->transition($p, P::OPEN_PROTECTED, $source, [
                'protection_status' => P::PS_PROTECTED,
                'protected_at' => now(),
                'last_reconciled_at' => now(),
                'last_error' => null,
            ], ['sl_algo_id' => $p->sl_algo_id, 'tp_algo_id' => $p->tp_algo_id, 'sl_trigger_price' => (float) $p->sl_trigger_price], 'protection_verified');

            return $p;
        }

        $reason = (string) $res['reason'];
        if ($this->sm->transition($p, P::PROTECTION_FAILED, $source, ['protection_status' => P::PS_FAILED, 'last_error' => mb_substr($reason, 0, 500)], ['reason' => $reason], 'protection_failed', 'critical')) {
            return $this->exits->emergencyExit($p, $gw, $source, $reason);
        }

        return $p;
    }

    /**
     * @return array{state: string, qty: float, avg: float, order_id: string, basis: string}  state: filled | none | unknown
     */
    public function resolveEntryFill(P $p, ManualExchangeGateway $gw, ?array $order): array
    {
        $attempts = max(1, (int) config('quick_trade_manual.fill_query_attempts', 3));
        $cur = $order;
        $sawNotFound = false;
        $sawError = false;

        for ($i = 0; $i < $attempts; $i++) {
            if ($cur !== null && $cur['executed_qty'] > 0) {
                $avg = $cur['avg_price'];
                if ($avg <= 0 && $cur['order_id'] !== '') {
                    $f = $gw->orderFills($p->symbol, $cur['order_id']);
                    $avg = (float) ($f['avg_price'] ?? 0);
                }
                if ($avg > 0) {
                    return ['state' => 'filled', 'qty' => $cur['executed_qty'], 'avg' => $avg, 'order_id' => $cur['order_id'], 'basis' => 'order'];
                }
            } elseif ($cur !== null && in_array($cur['status'], ['EXPIRED', 'CANCELED', 'REJECTED'], true)) {
                return ['state' => 'none', 'qty' => 0.0, 'avg' => 0.0, 'order_id' => $cur['order_id'], 'basis' => 'order_terminal_no_fill'];
            }

            if ($i + 1 < $attempts) {
                $ms = (int) config('quick_trade_manual.fill_query_sleep_ms', 300);
                if ($ms > 0) {
                    usleep($ms * 1000);
                }
            }
            try {
                $q = $gw->queryOrder($p->symbol, $cur['order_id'] ?? null, (string) $p->entry_client_order_id);
                if ($q === null) {
                    $sawNotFound = true;
                } else {
                    $cur = $q;
                }
            } catch (ManualExchangeException) {
                $sawError = true;
            }
        }

        // Last resort: the position itself is the truth.
        try {
            $pos = $gw->position($p->symbol, $p->positionSide());
        } catch (ManualExchangeException) {
            return ['state' => 'unknown', 'qty' => 0.0, 'avg' => 0.0, 'order_id' => (string) ($cur['order_id'] ?? ''), 'basis' => 'position_unreadable'];
        }
        if (abs($pos['amount']) > 0) {
            $avg = $pos['entry_price'] > 0 ? $pos['entry_price'] : ((float) ($cur['avg_price'] ?? 0));
            if ($avg > 0) {
                return ['state' => 'filled', 'qty' => abs($pos['amount']), 'avg' => $avg, 'order_id' => (string) ($cur['order_id'] ?? ''), 'basis' => 'exchange_position'];
            }

            return ['state' => 'unknown', 'qty' => 0.0, 'avg' => 0.0, 'order_id' => (string) ($cur['order_id'] ?? ''), 'basis' => 'position_without_price'];
        }
        if ($cur === null && $sawNotFound && ! $sawError) {
            return ['state' => 'none', 'qty' => 0.0, 'avg' => 0.0, 'order_id' => '', 'basis' => 'order_not_found_position_flat'];
        }

        return ['state' => 'unknown', 'qty' => 0.0, 'avg' => 0.0, 'order_id' => (string) ($cur['order_id'] ?? ''), 'basis' => 'unresolved'];
    }

    /**
     * Entry did not result in a position: release the reserved stake and end in ENTRY_FAILED.
     * @param  bool  $rejectedByExchange  true = definite exchange rejection (surfaced to the caller as an error)
     */
    private function failEntry(P $p, string $source, string $message, bool $rejectedByExchange): P
    {
        DB::transaction(function () use ($p, $source, $message): void {
            $ok = $this->sm->transition($p, P::ENTRY_FAILED, $source, [
                'last_error' => mb_substr($message, 0, 500),
                'wallet_settled_at' => now(),
                'closed_at' => now(),
            ], ['reason' => $message], 'entry_failed', 'high');
            if ($ok && $p->wallet_account_id) {
                $this->wallets->cancelReservation((string) $p->workspace_id, (string) $p->wallet_account_id, (float) $p->stake_amount, [
                    'trace_id' => (string) $p->id,
                    'symbol' => $p->symbol,
                    'source' => 'quick_trade_manual_sl_tp',
                ]);
            }
        });

        if ($rejectedByExchange) {
            throw new RuntimeException('entry_rejected: '.$message);
        }

        return $p;
    }

    /**
     * User-initiated close. Membership is checked by the caller. Deliberately does NOT need the
     * feature flags: turning the feature off must never trap a user in an open position.
     */
    public function close(string $workspaceId, string $positionId, ?string $userId): P
    {
        $p = P::query()->where('workspace_id', $workspaceId)->whereKey($positionId)->firstOrFail();
        if ($p->isTerminal() || in_array($p->status, [P::CLOSING, P::RECONCILIATION, P::EMERGENCY_EXIT], true)) {
            return $p;   // duplicate close request: already closing/closed, no second order
        }
        if (! in_array($p->status, [P::OPEN_PROTECTED, P::CLOSE_FAILED], true)) {
            throw new RuntimeException('not_closable: Position is '.$p->status.' and cannot be closed manually right now.');
        }

        $lock = Cache::lock('qtm:pos:'.$p->id, 90);
        if (! $lock->get()) {
            return $p->refresh();   // reconciler or another click holds it
        }
        try {
            $p->refresh();
            if (! in_array($p->status, [P::OPEN_PROTECTED, P::CLOSE_FAILED], true)) {
                return $p;
            }
            $integration = Integration::query()->where('workspace_id', $workspaceId)->whereKey($p->integration_id)->first();
            if (! $integration) {
                throw new RuntimeException('integration_missing: The exchange connection for this position no longer exists.');
            }
            $gw = $this->gateways->make($integration);

            return $this->exits->manualClose($p, $gw, $userId);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> numbers for the manual-close confirmation dialog */
    public function closePreview(string $workspaceId, string $positionId): array
    {
        $p = P::query()->where('workspace_id', $workspaceId)->whereKey($positionId)->firstOrFail();
        if ($p->isTerminal()) {
            throw new RuntimeException('not_closable: Position is already '.$p->status.'.');
        }
        $integration = Integration::query()->where('workspace_id', $workspaceId)->whereKey($p->integration_id)->firstOrFail();
        $gw = $this->gateways->make($integration);
        try {
            $mark = $gw->markPrice($p->symbol);
            $pos = $gw->position($p->symbol, $p->positionSide());
        } catch (ManualExchangeException $e) {
            throw new RuntimeException('exchange_unavailable: '.$e->getMessage());
        }

        $qty = abs($pos['amount']) > 0 ? abs($pos['amount']) : (float) $p->executed_quantity;
        $entry = (float) $p->entry_price;
        $sign = $p->isLong() ? 1 : -1;
        $price = $mark['mark_price'];
        $gross = round(($price - $entry) * $qty * $sign, 8);
        $rate = (float) config('quick_trade_manual.taker_fee_rate', 0.0005);
        $entryFee = $p->entry_fee !== null ? (float) $p->entry_fee : round($entry * $qty * $rate, 8);
        $exitFee = round($price * $qty * $rate, 8);

        return [
            'position_id' => $p->id,
            'symbol' => $p->symbol,
            'direction' => $p->direction,
            'quantity' => $qty,
            'mark_price' => $price,
            'entry_price' => $entry,
            'estimated_gross_pnl' => $gross,
            'estimated_entry_fee' => $entryFee,
            'estimated_exit_fee' => $exitFee,
            'estimated_net_pnl' => round($gross - $entryFee - $exitFee, 8),
            'funding_note' => 'Funding paid/received while open is settled into the final P&L and is not included in this estimate.',
            'slippage_warning' => 'A MARKET close fills at the best available price, which can differ from the mark price shown, especially in fast markets.',
            'already_flat_on_exchange' => abs($pos['amount']) <= 0.0,
        ];
    }

    // ---------------------------------------------------------------- guards

    private function assertNoConflicts(string $workspaceId, string $symbol): void
    {
        if (P::query()->where('workspace_id', $workspaceId)->where('symbol', $symbol)->whereNotIn('status', P::TERMINAL)->exists()) {
            throw new RuntimeException('duplicate_manual_position: A Manual SL/TP position is already active on this symbol.');
        }
        $max = (int) config('quick_trade_manual.max_open_positions', 3);
        if (P::query()->where('workspace_id', $workspaceId)->whereNotIn('status', P::TERMINAL)->count() >= $max) {
            throw new RuntimeException("max_open_positions: At most {$max} Manual SL/TP positions can be open at once.");
        }
        if (QuickTrade::query()->where('workspace_id', $workspaceId)->where('symbol', $symbol)->where('mode', 'live')->where('status', 'open')->exists()) {
            throw new RuntimeException('time_based_position_open: A Time-Based Quick Trade is open on this symbol. On a one-way account both would be the same exchange position, so Manual mode refuses.');
        }
    }

    /** null = live trading is allowed for this workspace, else the reason. */
    private function liveGateReason(string $workspaceId): ?string
    {
        try {
            $this->modeGuard->assertWorkspaceMode($workspaceId, true);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        $state = $this->settings->tradingState($workspaceId);
        if (! ($state['live_ready'] ?? false)) {
            return (string) ($state['live_ready_reason'] ?? 'Live quick trading is not ready for this workspace.');
        }

        return null;
    }
}
