<?php

namespace App\Domain\QuickTradeManual;

use App\Domain\QuickTradeManual\Exchange\BinanceAlgoApi;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGateway;
use App\Models\QuickTradeManualPosition as P;
use App\Models\QuickTradeManualProtectionPair as Pair;
use Illuminate\Support\Str;

/** Activates TP observation levels and manages their exchange-native reversal stops. */
class ManualProtectionPairService
{
    public function __construct(private readonly ManualPositionStateMachine $sm)
    {
    }

    public function reconcile(P $p, ManualExchangeGateway $gw, float $mark): void
    {
        if ($p->noStopLoss() || $mark <= 0) return;
        $rules = $gw->symbolRules($p->symbol);
        foreach ($p->protectionPairs()->whereIn('status', [Pair::PENDING, Pair::ACTIVE])->orderBy('level')->get() as $pair) {
            if ($pair->status === Pair::PENDING) {
                $hit = $p->isLong() ? $mark >= (float) $pair->tp_price : $mark <= (float) $pair->tp_price;
                if ($hit) $this->activate($p, $pair, $gw, $mark, (float) $rules['tick_size']);
            } elseif ($pair->sl_mode === 'trailing') {
                $this->trail($p, $pair, $gw, $mark, (float) $rules['tick_size']);
            }
        }
    }

    /** Update a pending/active pair; active protection is replaced only after verification. */
    public function update(P $p, Pair $pair, ManualExchangeGateway $gw, array $input): array
    {
        if ($p->noStopLoss() || ! in_array($p->status, [P::OPEN_PROTECTED, P::CLOSE_FAILED], true)) return ['ok' => false, 'reason' => 'position_not_editable'];
        $tpMode = strtolower((string) ($input['tp_mode'] ?? $pair->tp_mode));
        $slMode = strtolower((string) ($input['sl_mode'] ?? $pair->sl_mode));
        $slInput = strtolower((string) ($input['sl_input_mode'] ?? $pair->sl_input_mode));
        $tpValue = (float) ($input['tp_value'] ?? $pair->tp_value);
        $slValue = (float) ($input['sl_value'] ?? $pair->sl_value);
        if (! in_array($tpMode, ['percent', 'price'], true) || ! in_array($slMode, ['fixed', 'trailing'], true) || ! in_array($slInput, ['percent', 'price', 'distance'], true) || ($slMode === 'trailing' && $slInput === 'price') || $tpValue <= 0 || $slValue <= 0) return ['ok' => false, 'reason' => 'invalid_protection_pair'];
        $entry = (float) $p->entry_price;
        $tp = $tpMode === 'price' ? $tpValue : ($p->isLong() ? $entry * (1 + $tpValue / 100) : $entry * (1 - $tpValue / 100));
        $stop = $slInput === 'price' ? $slValue : ($slInput === 'distance' ? ($p->isLong() ? $tp - $slValue : $tp + $slValue) : ($p->isLong() ? $tp * (1 - $slValue / 100) : $tp * (1 + $slValue / 100)));
        if (($p->isLong() && ($tp <= $entry || $stop >= $tp)) || (! $p->isLong() && ($tp >= $entry || $stop <= $tp))) return ['ok' => false, 'reason' => 'invalid_protection_pair_side'];
        $tick = (float) $gw->symbolRules($p->symbol)['tick_size'];
        $oldId = (string) ($pair->sl_algo_id ?? ''); $oldClient = (string) ($pair->sl_client_algo_id ?? '');
        if ($pair->status === Pair::ACTIVE) {
            $placed = $this->placeVerified($p, $gw, $pair, $stop, $tick);
            if (! $placed['ok']) return $placed;
            try { if ($oldId !== '' || $oldClient !== '') $gw->cancelProtective($p->symbol, $oldId ?: null, $oldClient ?: null); }
            catch (ManualExchangeException $e) { $this->sm->note($p, 'protection_pair_old_stop_cancel_failed', 'api', ['level' => $pair->level, 'error' => $e->getMessage()], 'critical'); return ['ok' => false, 'reason' => 'old_stop_cancel_failed']; }
            $pair->update(['tp_mode' => $tpMode, 'tp_value' => $tpValue, 'tp_price' => $tp, 'sl_mode' => $slMode, 'sl_input_mode' => $slInput, 'sl_value' => $slValue, 'sl_price' => $stop, 'active_stop_price' => $stop, 'sl_algo_id' => $placed['algo_id'], 'sl_client_algo_id' => $placed['client_algo_id'], 'updated_stop_at' => now()]);
        } else {
            $pair->update(['tp_mode' => $tpMode, 'tp_value' => $tpValue, 'tp_price' => $tp, 'sl_mode' => $slMode, 'sl_input_mode' => $slInput, 'sl_value' => $slValue, 'sl_price' => $stop, 'active_stop_price' => $stop]);
        }
        $this->sm->note($p, 'protection_pair_updated', 'api', ['level' => $pair->level, 'status' => $pair->status, 'tp_price' => $tp, 'stop_price' => $stop]);
        return ['ok' => true, 'reason' => null];
    }

    public function cancel(P $p, Pair $pair, ManualExchangeGateway $gw): array
    {
        if ($pair->status === Pair::ACTIVE && ($pair->sl_algo_id || $pair->sl_client_algo_id)) {
            try {
                $gw->cancelProtective($p->symbol, $pair->sl_algo_id ?: null, $pair->sl_client_algo_id ?: null);
                $left = $gw->queryProtective($p->symbol, $pair->sl_algo_id ?: null, $pair->sl_client_algo_id ?: null);
                if ($left !== null && in_array($left['status'], BinanceAlgoApi::WORKING_STATUSES, true)) return ['ok' => false, 'reason' => 'pair_stop_cancel_unconfirmed'];
            } catch (ManualExchangeException $e) { return ['ok' => false, 'reason' => 'pair_stop_cancel_failed: '.$e->getMessage()]; }
        }
        $pair->update(['status' => Pair::CANCELLED, 'sl_status' => 'CANCELLED']);
        $this->sm->note($p, 'protection_pair_cancelled', 'api', ['level' => $pair->level]);
        return ['ok' => true, 'reason' => null];
    }

    private function activate(P $p, Pair $pair, ManualExchangeGateway $gw, float $mark, float $tick): void
    {
        $stop = (float) ($pair->sl_price ?: $pair->active_stop_price);
        if ($pair->sl_mode === 'trailing') {
            $stop = $this->trailingStop($p, $pair, (float) $pair->tp_price);
        }
        $placed = $this->placeVerified($p, $gw, $pair, $stop, $tick);
        if (! $placed['ok']) {
            $this->sm->note($p, 'protection_pair_activation_failed', 'reconciler', ['level' => $pair->level, 'reason' => $placed['reason']], 'high');
            return;
        }
        $pair->update([
            'status' => Pair::ACTIVE,
            'sl_algo_id' => $placed['algo_id'], 'sl_client_algo_id' => $placed['client_algo_id'], 'sl_status' => 'WORKING',
            'sl_price' => $stop, 'active_stop_price' => $stop, 'peak_price' => $p->isLong() ? max((float) $pair->tp_price, $mark) : null,
            'trough_price' => $p->isLong() ? null : min((float) $pair->tp_price, $mark), 'activated_at' => now(),
        ]);
        $this->sm->note($p, 'protection_pair_activated', 'reconciler', ['level' => $pair->level, 'tp_price' => (float) $pair->tp_price, 'stop_price' => $stop]);
    }

    private function trail(P $p, Pair $pair, ManualExchangeGateway $gw, float $mark, float $tick): void
    {
        $oldPeak = $p->isLong() ? (float) ($pair->peak_price ?: 0) : (float) ($pair->trough_price ?: 0);
        $newPeak = $p->isLong() ? max($oldPeak, $mark) : min($oldPeak > 0 ? $oldPeak : $mark, $mark);
        $newStop = $this->trailingStop($p, $pair, $newPeak);
        $oldStop = (float) ($pair->active_stop_price ?: 0);
        $tightened = $p->isLong() ? $newStop > $oldStop + $tick / 2 : $newStop < $oldStop - $tick / 2;
        if (! $tightened) return;
        $placed = $this->placeVerified($p, $gw, $pair, $newStop, $tick);
        if (! $placed['ok']) {
            $this->sm->note($p, 'protection_pair_trailing_update_failed', 'reconciler', ['level' => $pair->level, 'reason' => $placed['reason']], 'high');
            return;
        }
        $oldId = (string) ($pair->sl_algo_id ?? '');
        $oldClient = (string) ($pair->sl_client_algo_id ?? '');
        if ($oldId !== '' || $oldClient !== '') {
            try { $gw->cancelProtective($p->symbol, $oldId ?: null, $oldClient ?: null); } catch (ManualExchangeException $e) {
                $this->sm->note($p, 'protection_pair_old_stop_cancel_failed', 'reconciler', ['level' => $pair->level, 'error' => $e->getMessage()], 'critical');
                return;
            }
        }
        $pair->update(['sl_algo_id' => $placed['algo_id'], 'sl_client_algo_id' => $placed['client_algo_id'], 'active_stop_price' => $newStop, 'sl_price' => $newStop, 'peak_price' => $p->isLong() ? $newPeak : null, 'trough_price' => $p->isLong() ? null : $newPeak, 'updated_stop_at' => now()]);
    }

    private function trailingStop(P $p, Pair $pair, float $anchor): float
    {
        return $pair->sl_input_mode === 'distance'
            ? ($p->isLong() ? $anchor - (float) $pair->sl_value : $anchor + (float) $pair->sl_value)
            : ($p->isLong() ? $anchor * (1 - (float) $pair->sl_value / 100) : $anchor * (1 + (float) $pair->sl_value / 100));
    }

    /** Place and query-confirm a pair stop. Existing stop is never cancelled by this method. */
    private function placeVerified(P $p, ManualExchangeGateway $gw, Pair $pair, float $stop, float $tick): array
    {
        $client = 'qtm-p'.$pair->level.'-'.substr(str_replace('-', '', (string) $p->id), 0, 18).'-'.substr(str_replace('-', '', (string) Str::uuid()), 0, 8);
        try {
            $ack = $gw->placeProtective(['symbol' => $p->symbol, 'side' => $p->closeSide(), 'position_side' => $p->positionSide(), 'type' => BinanceAlgoApi::TYPE_STOP, 'trigger_price' => ManualTradeMath::formatToStep($stop, $tick), 'client_algo_id' => $client, 'working_type' => (string) config('quick_trade_manual.working_type', 'MARK_PRICE')]);
        } catch (ManualExchangeException $e) {
            if (! $e->ambiguous) return ['ok' => false, 'reason' => $e->getMessage()];
            try { $found = $gw->queryProtective($p->symbol, null, $client); } catch (ManualExchangeException) { $found = null; }
            if ($found === null) return ['ok' => false, 'reason' => 'activation_ack_missing'];
            $ack = $found;
        }
        try {
            $found = $gw->queryProtective($p->symbol, $ack['algo_id'], $client);
        } catch (ManualExchangeException $e) {
            // A new pair stop is never accepted as active without verification.
            // Best-effort cleanup prevents an untracked close order from surviving.
            try { $gw->cancelProtective($p->symbol, $ack['algo_id'] ?? null, $client); } catch (ManualExchangeException) {}
            return ['ok' => false, 'reason' => $e->getMessage()];
        }
        if ($found === null || ! in_array($found['status'], BinanceAlgoApi::WORKING_STATUSES, true) || abs((float) $found['trigger_price'] - ManualTradeMath::formatToStep($stop, $tick)) > $tick / 2 + 1e-12) {
            try { $gw->cancelProtective($p->symbol, $ack['algo_id'] ?? null, $client); } catch (ManualExchangeException) {}
            return ['ok' => false, 'reason' => 'activation_not_verified'];
        }
        return ['ok' => true, 'algo_id' => (string) ($found['algo_id'] ?? $ack['algo_id']), 'client_algo_id' => $client, 'reason' => null];
    }
}
