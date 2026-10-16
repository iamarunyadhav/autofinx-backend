<?php

namespace App\Domain\QuickTradeManual;

use App\Domain\QuickTradeManual\Exchange\BinanceAlgoApi;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGateway;
use App\Models\QuickTradeManualPosition as P;

/**
 * Exchange-native protection for a manual position: place the conditional close-position
 * orders, VERIFY the exchange acknowledged them, persist the real status, and clean them up.
 *
 * Rule that everything here serves: protection_status is PROTECTED only after the exchange
 * itself answered a query for the order we placed with a working status and the trigger price
 * we asked for. A missing/odd field, a failed query or a wrong status is NOT protection.
 */
class ManualProtectionService
{
    /** Post-fill: the stop must sit inside this share of the EXCHANGE-reported liquidation distance. */
    public const POST_FILL_LIQ_RATIO = 0.9;

    public function __construct(private readonly ManualPositionStateMachine $sm)
    {
    }

    /**
     * Compute triggers from the ACTUAL fill, place + verify SL (and optional TP).
     * Does not change the high-level status (caller does); it only persists protection facts.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function protect(P $p, ManualExchangeGateway $gw): array
    {
        if ($p->noStopLoss()) {
            return $this->protectWithoutStop($p, $gw);
        }

        try {
            $rules = $gw->symbolRules($p->symbol);
        } catch (ManualExchangeException $e) {
            return ['ok' => false, 'reason' => 'rules_unavailable: '.$e->getMessage()];
        }
        $tick = $rules['tick_size'];
        $entry = (float) $p->entry_price;
        if ($entry <= 0) {
            return ['ok' => false, 'reason' => 'entry_price_unknown'];
        }

        $sl = $p->sl_trigger_price !== null && (float) $p->sl_trigger_price > 0
            ? (float) $p->sl_trigger_price
            : ManualTradeMath::slTrigger($p->direction, $entry, (float) $p->sl_pct, $tick);
        $slOk = $p->isLong() ? $sl < $entry : $sl > $entry;
        if (! $slOk) {
            return ['ok' => false, 'reason' => 'stop_loss_not_on_loss_side_of_entry'];
        }
        $tp = null;
        if ($p->tp_pct !== null) {
            $tp = $p->tp_trigger_price !== null && (float) $p->tp_trigger_price > 0
                ? (float) $p->tp_trigger_price
                : ManualTradeMath::tpTrigger($p->direction, $entry, (float) $p->tp_pct, $tick);
        }
        $this->sm->patch($p, ['sl_trigger_price' => $sl, 'tp_trigger_price' => $tp]);

        $slResult = $this->ensureLeg($p, $gw, 'sl', $sl, $tick);
        if (! $slResult['ok']) {
            $this->sm->patch($p, ['sl_status' => 'FAILED', 'protection_status' => P::PS_FAILED]);

            return ['ok' => false, 'reason' => $slResult['reason']];
        }

        if ($tp !== null) {
            $tpResult = $this->ensureLeg($p, $gw, 'tp', $tp, $tick);
            if (! $tpResult['ok']) {
                // Optional leg: the position is still SL-protected. Say so loudly, do not pretend.
                $this->sm->patch($p, ['tp_status' => 'FAILED']);
                $this->sm->note($p, 'take_profit_not_active', 'system', ['reason' => $tpResult['reason']], 'high');
            }
        }

        // Optional exchange-native trailing exit. Binance tracks the favourable
        // peak/trough and only closes after the callback distance is breached;
        // this avoids server polling latency and naturally tolerates a small
        // reversal before the close.
        if ($p->trailing_pct !== null) {
            $activation = null;
            if ($p->trailing_activation_pct !== null && (float) $p->trailing_activation_pct > 0) {
                $activation = $p->isLong()
                    ? $entry * (1 + (float) $p->trailing_activation_pct / 100)
                    : $entry * (1 - (float) $p->trailing_activation_pct / 100);
                $this->sm->patch($p, ['trailing_activation_price' => ManualTradeMath::formatToStep($activation, $tick)]);
            }
            $trail = $this->ensureLeg($p, $gw, 'trailing', $activation ?? 0.0, $tick);
            if (! $trail['ok']) {
                $this->sm->patch($p, ['trailing_status' => 'FAILED']);
                $this->sm->note($p, 'trailing_not_active', 'system', ['reason' => $trail['reason']], 'high');
            }
        }

        // Cross-check against the exchange's own liquidation price (the pre-trade figure was an estimate).
        try {
            $pos = $gw->position($p->symbol, $p->positionSide());
            if ($pos['liquidation_price'] !== null) {
                $liqDist = abs($entry - $pos['liquidation_price']);
                $slDist = abs($entry - $sl);
                $liqOnLossSide = $p->isLong() ? $pos['liquidation_price'] < $entry : $pos['liquidation_price'] > $entry;
                $this->sm->patch($p, ['exchange_liquidation_price' => $pos['liquidation_price']]);
                if ($liqOnLossSide && ($liqDist <= 0 || $slDist > $liqDist * self::POST_FILL_LIQ_RATIO)) {
                    return ['ok' => false, 'reason' => sprintf('stop_loss_not_inside_exchange_liquidation: stop %.8f vs exchange liquidation %.8f', $sl, $pos['liquidation_price'])];
                }
            }
        } catch (ManualExchangeException) {
            // A failed cross-check read does not remove the verified stop; the reconciler re-reads.
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * Explicit no-stop-loss trade: NEVER places a stop order. Only the optional take-profit leg is handled
     * (same rules as the normal path: a failed TP is reported, it does not fail or close the position).
     * Nothing here depends on an exchange read succeeding, so a transient read error cannot turn into an
     * emergency close of a trade that has nothing to protect.
     *
     * @return array{ok: bool, reason: ?string}
     */
    private function protectWithoutStop(P $p, ManualExchangeGateway $gw): array
    {
        $entry = (float) $p->entry_price;
        if ($entry <= 0) {
            return ['ok' => false, 'reason' => 'entry_price_unknown'];
        }
        if ($p->tp_pct !== null) {
            try {
                $tick = $gw->symbolRules($p->symbol)['tick_size'];
                $tp = $p->tp_trigger_price !== null && (float) $p->tp_trigger_price > 0
                    ? (float) $p->tp_trigger_price
                    : ManualTradeMath::tpTrigger($p->direction, $entry, (float) $p->tp_pct, $tick);
                $this->sm->patch($p, ['tp_trigger_price' => $tp]);
                $tpResult = $this->ensureLeg($p, $gw, 'tp', $tp, $tick);
            } catch (ManualExchangeException $e) {
                $tpResult = ['ok' => false, 'reason' => 'rules_unavailable: '.$e->getMessage()];
            }
            if (! $tpResult['ok']) {
                $this->sm->patch($p, ['tp_status' => 'FAILED']);
                $this->sm->note($p, 'take_profit_not_active', 'system', ['reason' => $tpResult['reason']], 'high');
            }
        }
        try {
            $pos = $gw->position($p->symbol, $p->positionSide());
            if ($pos['liquidation_price'] !== null) {
                $this->sm->patch($p, ['exchange_liquidation_price' => $pos['liquidation_price']]);
            }
        } catch (ManualExchangeException) {
            // informational only; the reconciler re-reads it
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * Idempotent: resumes a previous attempt by querying our recorded ids first, never places a
     * second live order while one is verified-working, uses a fresh client id per generation.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function ensureLeg(P $p, ManualExchangeGateway $gw, string $leg, float $trigger, float $tick): array
    {
        $isSl = $leg === 'sl';
        $isTrailing = $leg === 'trailing';
        $type = $isSl ? BinanceAlgoApi::TYPE_STOP : ($isTrailing ? 'TRAILING_STOP_MARKET' : BinanceAlgoApi::TYPE_TAKE_PROFIT);
        $idCol = $leg.'_algo_id';
        $clientCol = $leg.'_client_algo_id';

        // 1) a previous attempt (crash/retry/lost-ack): is it alive on the exchange? The generation
        //    counter is persisted BEFORE every placement, so even if the DB lost the ids after a
        //    successful placement, the deterministic client id of the last generation finds it.
        $state = (array) ($p->last_exchange_state ?? []);
        $lastGen = (int) ($state[$leg.'_gen'] ?? 0);
        $probeId = ($p->{$idCol} ?? '') !== '' ? (string) $p->{$idCol} : null;
        $probeClient = ($p->{$clientCol} ?? '') !== '' ? (string) $p->{$clientCol} : ($lastGen > 0 ? $this->clientId($p, $leg, $lastGen) : null);
        if ($probeId !== null || $probeClient !== null) {
            try {
                $found = $gw->queryProtective($p->symbol, $probeId, $probeClient);
            } catch (ManualExchangeException) {
                $found = null;
            }
            if ($found !== null && $this->matches($found, $p, $type, $trigger, $tick, $probeId)) {
                $this->markVerified($p, $leg, $found['algo_id'], $found['client_algo_id'] !== '' ? $found['client_algo_id'] : (string) $probeClient);

                return ['ok' => true, 'reason' => null];
            }
        }

        // 2) place a new generation
        $gen = $lastGen + 1;
        $this->mergeState($p, [$leg.'_gen' => $gen]);
        $clientId = $this->clientId($p, $leg, $gen);

        $ack = null;
        try {
            $ack = $gw->placeProtective([
                'symbol' => $p->symbol,
                'side' => $p->closeSide(),
                'position_side' => $p->positionSide(),
                'type' => $type,
                'trigger_price' => ManualTradeMath::formatToStep($trigger, $tick),
                'callback_rate' => $isTrailing ? number_format((float) $p->trailing_pct, 4, '.', '') : null,
                'activation_price' => $isTrailing && $trigger > 0 ? ManualTradeMath::formatToStep($trigger, $tick) : null,
                'client_algo_id' => $clientId,
                'working_type' => (string) config('quick_trade_manual.working_type', 'MARK_PRICE'),
            ]);
        } catch (ManualExchangeException $e) {
            if (! $e->ambiguous) {
                return ['ok' => false, 'reason' => $leg.'_rejected: '.$e->getMessage()];
            }
            // Outcome unknown: the order may exist. Ask by the client id we chose.
            try {
                $found = $gw->queryProtective($p->symbol, null, $clientId);
            } catch (ManualExchangeException) {
                $found = null;
            }
            if ($found === null) {
                return ['ok' => false, 'reason' => $leg.'_ack_missing: '.$e->getMessage()];
            }
            $ack = ['algo_id' => $found['algo_id'], 'client_algo_id' => $clientId, 'status' => $found['status']];
        }

        // Persist ids BEFORE verification so a crash here is recoverable by the reconciler.
        $this->sm->patch($p, [$idCol => $ack['algo_id'], $clientCol => $ack['client_algo_id'], $leg.'_status' => 'PLACED', $leg.'_verified_at' => null]);

        // 3) verify with the exchange (query, not the placement echo)
        $found = null;
        $attempts = 2;
        for ($i = 0; $i < $attempts && $found === null; $i++) {
            try {
                $found = $gw->queryProtective($p->symbol, $ack['algo_id'], $ack['client_algo_id']);
            } catch (ManualExchangeException) {
                $found = null;
            }
            if ($found === null && $i + 1 < $attempts) {
                $this->pause();
            }
        }
        if ($found === null || ! $this->matches($found, $p, $type, $trigger, $tick, $ack['algo_id'])) {
            // Might exist un-verified: try to remove it so no ghost conditional order survives.
            try {
                $gw->cancelProtective($p->symbol, $ack['algo_id'], $ack['client_algo_id']);
            } catch (ManualExchangeException) {
            }

            return ['ok' => false, 'reason' => $leg.'_not_verified: '.($found === null ? 'exchange did not confirm the order' : 'exchange answer did not match (status '.$found['status'].', trigger '.$found['trigger_price'].')')];
        }

        $this->markVerified($p, $leg, $ack['algo_id'], $ack['client_algo_id']);

        return ['ok' => true, 'reason' => null];
    }

    /**
     * Read-only health check used by the reconciler.
     * @return array{state: string, sl: ?array, tp: ?array}  state: OK | LOST | UNKNOWN
     */
    public function inspect(P $p, ManualExchangeGateway $gw): array
    {
        try {
            // A deliberate no-stop trade has no stop to look for: never report it LOST.
            $sl = $p->noStopLoss() ? null : $gw->queryProtective($p->symbol, $p->sl_algo_id ?: null, $p->sl_client_algo_id ?: null);
            $tp = $p->tp_pct !== null ? $gw->queryProtective($p->symbol, $p->tp_algo_id ?: null, $p->tp_client_algo_id ?: null) : null;
        } catch (ManualExchangeException) {
            return ['state' => 'UNKNOWN', 'sl' => null, 'tp' => null];
        }
        if ($p->noStopLoss()) {
            return ['state' => 'NO_STOP', 'sl' => null, 'tp' => $tp];
        }
        $slWorking = $sl !== null && in_array($sl['status'], BinanceAlgoApi::WORKING_STATUSES, true);

        return ['state' => $slWorking ? 'OK' : 'LOST', 'sl' => $sl, 'tp' => $tp];
    }

    /**
     * Remove any still-live SL/TP of a position that is flat/closing. A leftover closePosition
     * order can later close an UNRELATED position on the same symbol, so this is verified, not assumed.
     *
     * @return bool true = no live protective order remains
     */
    public function cleanup(P $p, ManualExchangeGateway $gw): bool
    {
        $clear = true;
        foreach (['sl', 'tp', 'trailing'] as $leg) {
            $idCol = $leg.'_algo_id';
            $clientCol = $leg.'_client_algo_id';
            $statusCol = $leg.'_status';
            if ((($p->{$idCol} ?? '') === '' && ($p->{$clientCol} ?? '') === '') || in_array($p->{$statusCol}, ['CANCELLED', 'TRIGGERED', 'GONE', 'NONE'], true)) {
                continue;
            }
            try {
                $q = $gw->queryProtective($p->symbol, $p->{$idCol} ?: null, $p->{$clientCol} ?: null);
                if ($q === null) {
                    $this->sm->patch($p, [$statusCol => 'GONE']);
                    continue;
                }
                if (in_array($q['status'], BinanceAlgoApi::TRIGGERED_STATUSES, true)) {
                    $this->sm->patch($p, [$statusCol => 'TRIGGERED']);
                    continue;
                }
                if (in_array($q['status'], BinanceAlgoApi::INACTIVE_STATUSES, true)) {
                    $this->sm->patch($p, [$statusCol => 'CANCELLED']);
                    continue;
                }
                $gw->cancelProtective($p->symbol, $p->{$idCol} ?: null, $p->{$clientCol} ?: null);
                $after = $gw->queryProtective($p->symbol, $p->{$idCol} ?: null, $p->{$clientCol} ?: null);
                if ($after === null || in_array($after['status'], BinanceAlgoApi::INACTIVE_STATUSES, true)) {
                    $this->sm->patch($p, [$statusCol => 'CANCELLED']);
                } else {
                    $clear = false;
                    $this->sm->note($p, 'leftover_protective_order', 'system', ['leg' => $leg, 'status' => $after['status']], 'critical');
                }
            } catch (ManualExchangeException $e) {
                $clear = false;
                $this->sm->note($p, 'protective_cleanup_failed', 'system', ['leg' => $leg, 'error' => $e->getMessage()], 'high');
            }
        }

        return $clear;
    }

    // ---------------------------------------------------------------- internals

    private function matches(array $q, P $p, string $type, float $trigger, float $tick, ?string $expectAlgoId): bool
    {
        if (! in_array($q['status'], BinanceAlgoApi::WORKING_STATUSES, true)) {
            return false;
        }
        if ($expectAlgoId !== null && $expectAlgoId !== '' && $q['algo_id'] !== '' && $q['algo_id'] !== $expectAlgoId) {
            return false;
        }
        if ($q['symbol'] !== '' && $q['symbol'] !== strtoupper($p->symbol)) {
            return false;
        }
        if ($q['side'] !== '' && $q['side'] !== $p->closeSide()) {
            return false;
        }
        if ($q['type'] !== '' && $q['type'] !== $type) {
            return false;
        }
        if ($q['close_position'] === false) {
            return false;
        }

        if ($type === 'TRAILING_STOP_MARKET') {
            return isset($q['callback_rate']) && abs((float) $q['callback_rate'] - (float) $p->trailing_pct) <= 0.0001;
        }

        return abs($q['trigger_price'] - $trigger) <= $tick * 0.5 + 1e-12;
    }

    private function clientId(P $p, string $leg, int $gen): string
    {
        return sprintf('qtm-%s-%s-%d', $leg, substr(str_replace('-', '', (string) $p->id), 0, 22), $gen);
    }

    private function markVerified(P $p, string $leg, string $algoId, string $clientId): void
    {
        $this->sm->patch($p, [
            $leg.'_algo_id' => $algoId,
            $leg.'_client_algo_id' => $clientId,
            $leg.'_status' => 'WORKING',
            $leg.'_verified_at' => now(),
        ]);
    }

    private function mergeState(P $p, array $kv): void
    {
        $state = array_merge((array) ($p->last_exchange_state ?? []), $kv);
        $this->sm->patch($p, ['last_exchange_state' => ManualPositionStateMachine::jsonColumn($state)]);
    }

    private function pause(): void
    {
        $ms = (int) config('quick_trade_manual.fill_query_sleep_ms', 300);
        if ($ms > 0) {
            usleep($ms * 1000);
        }
    }
}
