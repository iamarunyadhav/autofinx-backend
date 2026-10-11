<?php

namespace Tests\Support\RatioBotReplay;

use App\Models\Order;
use App\Models\OrderFill;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use Illuminate\Support\Carbon;

/**
 * Continuous invariant audit + metrics for a replay run. Reads ONLY what the
 * production code persisted (state, decisions, lots, orders, fills) and what the
 * fake exchange independently recorded, and cross-checks one against the other.
 *
 * Invariant codes (see docs/ratio-bot-replay/REPORT.md for the verdict table):
 *  W1 balances never negative            L0 exact exchange ledger conservation
 *  R1 every exchange trade has exactly one DB order+fill     R2 DB fill == exchange trade (price/qty/fee)
 *  R3 every DB order is FILLED / maps to an exchange order   D1 decision<->order 1:1 & qty/price booked correctly
 *  D2 no two decisions share an order                        F1 fee charged on every fill at the documented rate
 *  X1 exchange filters respected by every executed order     X2 exchange rejections (bot sent an order the exchange refused)
 *  S1 trigger fired only when its documented condition holds S3 stop-loss condition met but no exit happened
 *  G1 profit guard: non-protective sell never below lot floor C1 no fill during cooldown
 *  A1 anchor/cost basis moves only as documented            I1 bot never believes it holds more base than the wallet has
 *  Q1 lots: remaining within [0, original], sells add up     Q2 lots <= wallet base
 *  P1 bot realized P&L vs independent wallet P&L (informational residual)
 */
class ReplayAuditor
{
    private const MAX_ANOMALIES_PER_CODE = 20;

    /** @var list<array> */
    public array $anomalies = [];
    /** @var array<string, int> */
    public array $anomalyCounts = [];
    /** @var list<array> */
    public array $notes = [];
    /** @var array<string, int> number of times each invariant was actually evaluated */
    public array $checks = [];
    /** @var array<string, array<string, array>> symbol => ym => counters */
    public array $monthly = [];
    /** @var array<string, array<string, int>> symbol => "ym|DECISION|status|trigger|guard" => count */
    public array $decisionStats = [];
    public array $ledger = [];       // independent average-cost ledger per symbol
    public array $equityPeak = [];
    public array $maxDrawdown = [];
    public float $startEquity = 0.0;
    public array $startBalances = [];
    public array $botRealizedAtMonthStart = [];
    private array $pre = [];
    private array $chunkTrades = [];
    private array $chunkEquity = [];
    private int $tradeCursor = 0;
    private int $rejectionCursor = 0;
    private array $symState = [];
    private string $ym = '';
    public string $monthlyKey = '';

    public function __construct(private readonly ReplayHarness $h, private readonly string $outDir, private readonly string $runId)
    {
        @mkdir($this->outDir, 0777, true);
        $this->startBalances = $h->exchange->free;
    }

    // ------------------------------------------------------------------ helpers

    private function cnt(string $code): void
    {
        $this->checks[$code] = ($this->checks[$code] ?? 0) + 1;
    }

    public function violate(string $code, string $severity, ?string $symbol, string $msg, array $ctx = []): void
    {
        $this->anomalyCounts[$code] = ($this->anomalyCounts[$code] ?? 0) + 1;
        if ($this->anomalyCounts[$code] > self::MAX_ANOMALIES_PER_CODE) {
            return;
        }
        $this->anomalies[] = ['code' => $code, 'severity' => $severity, 'at' => now()->toIso8601String(), 'symbol' => $symbol, 'msg' => $msg, 'ctx' => $ctx];
    }

    public function note(string $type, ?string $symbol, array $ctx = []): void
    {
        if (count($this->notes) < 2000) {
            $this->notes[] = ['type' => $type, 'at' => now()->toIso8601String(), 'symbol' => $symbol, 'ctx' => $ctx];
        }
    }

    private function m(string $sym, string $key, float|int $add = 1): void
    {
        $this->monthly[$sym][$this->ym][$key] = ($this->monthly[$sym][$this->ym][$key] ?? 0) + $add;
    }

    private function ymOf(): string
    {
        return now()->format('Y-m');
    }

    private function rules(string $sym): array
    {
        return $this->h->exchange->symbols[$sym];
    }

    private function price(string $sym): float
    {
        return (float) ($this->h->exchange->lastPrice[$sym] ?? 0);
    }

    private function equity(): float
    {
        return $this->h->exchange->totalValueUsdt();
    }

    // ------------------------------------------------------------------ before / after a tick

    public function beforeTick(int $tMs): void
    {
        $this->ym = $this->ymOf();
        if ($this->startEquity == 0.0) {
            $this->startEquity = $this->equity();
            foreach ($this->h->symbols as $s) {
                $this->equityPeak[$s] = $this->startEquity;
                $this->maxDrawdown[$s] = 0.0;
            }
            $this->equityPeak['ALL'] = $this->startEquity;
            $this->maxDrawdown['ALL'] = 0.0;
        }
        $this->pre = [];
        foreach ($this->h->symbols as $sym) {
            $cfg = $this->h->configs[$sym];
            $state = RatioBotState::where('ratio_bot_config_id', $cfg->id)->first();
            $lots = RatioBotLot::where('ratio_bot_config_id', $cfg->id)->where('status', RatioBotLot::STATUS_OPEN)->get(['id', 'buy_price', 'remaining_qty', 'buy_stage_index', 'original_qty', 'created_at'])
                ->map(fn ($l) => ['id' => (string) $l->id, 'buy_price' => (float) $l->buy_price, 'remaining' => (float) $l->remaining_qty, 'original' => (float) $l->original_qty, 'stage' => (int) $l->buy_stage_index])->all();
            $this->pre[$sym] = [
                'state' => $state ? [
                    'position_state' => $state->position_state, 'last_buy_price' => (float) $state->last_buy_price, 'last_buy_quantity' => (float) $state->last_buy_quantity,
                    'last_sell_price' => (float) $state->last_sell_price, 'active_anchor_price' => (float) $state->active_anchor_price, 'active_anchor_type' => $state->active_anchor_type,
                    'cooldown_until' => $state->cooldown_until, 'last_trade_time' => $state->last_trade_time, 'realized_pnl' => (float) $state->realized_pnl,
                    'cycle' => (int) $state->current_cycle_number,
                ] : null,
                'lots' => $lots,
                'free' => $this->h->exchange->free,
                'price' => $this->price($sym),
            ];
        }
    }

    public function afterTick(int $tMs): void
    {
        $ex = $this->h->exchange;
        $this->cnt('ticks');

        // W1 + L0 -------------------------------------------------------------------------------------------------
        $this->cnt('W1');
        foreach (['free' => $ex->free, 'locked' => $ex->locked] as $kind => $bal) {
            foreach ($bal as $asset => $units) {
                if ($units < 0) {
                    $this->violate('W1', 'critical', null, "negative {$kind} balance {$asset}={$units}", ['balances' => $ex->free]);
                }
            }
        }

        $newTrades = array_slice($ex->trades, $this->tradeCursor);
        $this->tradeCursor = count($ex->trades);
        $newRejections = array_slice($ex->rejections, $this->rejectionCursor);
        $this->rejectionCursor = count($ex->rejections);

        foreach ($newRejections as $rej) {
            $sev = in_array($rej['kind'], ['lot_size', 'notional', 'insufficient_balance', 'no_price'], true) ? 'high' : 'info';
            $this->violate('X2', $sev, $rej['symbol'] ?? null, 'exchange rejected an order the bot sent: ' . $rej['kind'], $rej);
            $this->m($rej['symbol'] ?? 'ALL', 'exchange_rejections');
        }

        foreach ($this->h->symbols as $sym) {
            $this->afterSymbol($sym, array_values(array_filter($newTrades, fn ($t) => $t['symbol'] === $sym)));
        }

        $this->cnt('L0');
        $this->checkLedgerConservation();
        $this->trackEquity($tMs);
        if ($ex->duplicateClientIdFills > 0) {
            $this->violate('D1', 'critical', null, 'exchange accepted a reused clientOrderId (double submission not stopped by the bot)', ['count' => $ex->duplicateClientIdFills]);
            $ex->duplicateClientIdFills = 0;
        }
    }

    private array $expected = [];

    /** Exact conservation: initial balances + sum of every trade's effects must equal the live balances (integer math). */
    private function checkLedgerConservation(): void
    {
        $ex = $this->h->exchange;
        $exp = $this->startBalances;
        foreach ($ex->trades as $t) {
            $rule = $ex->symbols[$t['symbol']];
            $base = $rule['base'];
            $quote = $rule['quote'];
            if ($t['side'] === 'BUY') {
                $exp[$quote] = ($exp[$quote] ?? 0) - $t['quoteQty'];
                $exp[$base] = ($exp[$base] ?? 0) + $t['qty'];
            } else {
                $exp[$base] = ($exp[$base] ?? 0) - $t['qty'];
                $exp[$quote] = ($exp[$quote] ?? 0) + $t['quoteQty'];
            }
            $exp[$t['commissionAsset']] = ($exp[$t['commissionAsset']] ?? 0) - $t['commission'];
        }
        foreach (array_unique(array_merge(array_keys($exp), array_keys($ex->free))) as $a) {
            if (($exp[$a] ?? 0) !== ($ex->free[$a] ?? 0)) {
                $this->violate('L0', 'critical', null, "balance {$a} != initial + sum(trades)", ['expected' => $exp[$a] ?? 0, 'actual' => $ex->free[$a] ?? 0]);
            }
        }
    }

    private function trackEquity(int $tMs): void
    {
        $eq = $this->equity();
        foreach (['ALL'] as $k) {
            $this->equityPeak[$k] = max($this->equityPeak[$k] ?? $eq, $eq);
            $dd = $this->equityPeak[$k] > 0 ? ($this->equityPeak[$k] - $eq) / $this->equityPeak[$k] : 0.0;
            $this->maxDrawdown[$k] = max($this->maxDrawdown[$k] ?? 0.0, $dd);
            $this->m('ALL', 'dd_max_month', 0); // ensure key
            $cur = $this->monthly['ALL'][$this->ym]['dd_max_month'] ?? 0.0;
            $this->monthly['ALL'][$this->ym]['dd_max_month'] = max($cur, $dd);
        }
        $this->m('ALL', 'ticks');
        if ($tMs % 3600000 === 0) {
            $row = ['t' => $tMs, 'iso' => now()->toIso8601String(), 'equity' => round($eq, 4)];
            foreach ($this->h->symbols as $s) {
                $row['px_' . $s] = $this->price($s);
                $row['pos_' . $s] = (string) ($this->symState[$s]['position_state'] ?? '');
            }
            $this->chunkEquity[] = $row;
        }
        if (! isset($this->monthly['ALL'][$this->ym]['equity_start'])) {
            $this->monthly['ALL'][$this->ym]['equity_start'] = $eq;
        }
        $this->monthly['ALL'][$this->ym]['equity_end'] = $eq;
    }

    // ------------------------------------------------------------------ per symbol

    private function afterSymbol(string $sym, array $newTrades): void
    {
        $ex = $this->h->exchange;
        $cfg = $this->h->configs[$sym]->fresh();
        $pre = $this->pre[$sym];
        $preState = $pre['state'] ?? null;
        $state = RatioBotState::where('ratio_bot_config_id', $cfg->id)->first();
        $rule = $this->rules($sym);
        $base = $rule['base'];
        $price = $this->price($sym);
        $this->symState[$sym] = ['position_state' => $state?->position_state];
        $this->m($sym, 'ticks');

        // time in USDT vs inventory
        $baseVal = FakeBinanceSpot::toFloat($ex->freeOf($base)) * $price;
        $quoteVal = FakeBinanceSpot::toFloat($ex->freeOf($rule['quote']));
        $this->m($sym, ($baseVal > $quoteVal) ? 'ticks_inventory' : 'ticks_usdt');
        $this->m($sym, 'inventory_value_sum', $baseVal);

        $tickStr = now()->format('Y-m-d H:i:s');
        $decisions = RatioBotDecision::where('ratio_bot_config_id', $cfg->id)->where('created_at', '>=', $tickStr)->get();

        // ---- decision statistics + trigger-condition audit (S1) ------------------------------------------------
        $costRatio = ((float) $cfg->fee_bps * 2 + (float) $cfg->max_slippage_bps) / 10000;
        $sl = $this->pct($cfg->stop_loss_pct);
        $tp = $this->pct($cfg->take_profit_pct);
        $sellTh = (float) $cfg->sell_threshold_ratio;
        $buyTh = abs((float) $cfg->buy_threshold_ratio);
        $hasProtectiveExit = false;
        foreach ($decisions as $d) {
            $meta = (array) ($d->metadata ?? []);
            $trigger = (string) ($meta['trigger'] ?? '');
            $guard = (string) ($meta['guard'] ?? '');
            $rb = (string) data_get($meta, 'risk_block.code', '');
            $key = implode('|', [$this->ym, $d->decision, $d->execution_status, $trigger, $guard, $rb]);
            $this->decisionStats[$sym][$key] = ($this->decisionStats[$sym][$key] ?? 0) + 1;

            if ($d->isTrade()) {
                $this->m($sym, 'trade_decisions');
                $this->auditTrigger($sym, $cfg, $d, $meta, $trigger, $preState, $costRatio, $sl, $tp, $sellTh, $buyTh);
                if (! empty($meta['protective_exit'])) {
                    $hasProtectiveExit = true;
                }
                if (in_array($d->execution_status, [RatioBotDecision::EXEC_FAILED], true)) {
                    $this->violate('E1', 'high', $sym, 'trade decision failed to execute: ' . mb_substr((string) $d->reason, 0, 160), ['decision' => $d->decision, 'trigger' => $trigger]);
                }
                if ($d->execution_status === RatioBotDecision::EXEC_PENDING || $d->execution_status === RatioBotDecision::EXEC_SUBMITTED) {
                    $this->violate('E2', 'high', $sym, 'decision left pending/submitted at end of tick', ['status' => $d->execution_status, 'decision' => $d->decision]);
                }
            }
        }

        // ---- S3: stop-loss condition met but no exit -------------------------------------------------------------
        if ($preState && $sl > 0 && $preState['last_buy_price'] > 0 && $preState['last_buy_quantity'] > 0
            && in_array($preState['position_state'], ['holding', 'partial_holding', 'partial_sold', 'recovery_monitoring'], true)) {
            $this->cnt('S3');
            $ratio = $pre['price'] / $preState['last_buy_price'] - 1;
            $priceNow = $price;
            $ratioNow = $priceNow / $preState['last_buy_price'] - 1;
            if ($ratioNow <= -$sl && ! $hasProtectiveExit) {
                $why = $decisions->map(fn ($d) => $d->decision . '/' . $d->execution_status . ': ' . mb_substr((string) $d->reason, 0, 120))->implode(' || ');
                $this->violate('S3', 'high', $sym, 'stop-loss condition met (ratio ' . round($ratioNow, 5) . ' <= -' . $sl . ') but no protective exit was produced this tick', ['pre_state' => $preState['position_state'], 'anchor' => $preState['last_buy_price'], 'price' => $priceNow, 'decisions' => $why]);
                $this->m($sym, 'sl_condition_without_exit');
            }
        }

        // ---- exchange trades: reconcile with DB (R1/R2/D1/F1/X1/C1/G1) ------------------------------------------
        foreach ($newTrades as $t) {
            $this->auditTrade($sym, $cfg, $state, $t, $preState, $pre);
        }

        // ---- anchors, cooldown, inventory coherence ---------------------------------------------------------------
        if ($state && $preState) {
            $this->auditAnchor($sym, $cfg, $state, $preState, $newTrades, $decisions);
        }
        if ($state) {
            $this->cnt('I1');
            $walletBase = FakeBinanceSpot::toFloat($ex->freeOf($base));
            $held = (float) $state->last_buy_quantity;
            if (in_array($state->position_state, ['holding', 'partial_holding', 'partial_sold'], true) && $held > $walletBase + 1e-7) {
                $this->violate('I1', 'high', $sym, 'bot believes it holds more base asset than the wallet has', ['state_qty' => $held, 'wallet_base' => $walletBase, 'position_state' => $state->position_state]);
            }
            if (in_array($state->position_state, ['blocked', 'paused', 'error_review_required'], true)) {
                $this->m($sym, 'ticks_blocked');
                $key = 'blocked:' . $state->block_reason_code;
                if (($this->symState[$sym]['last_block'] ?? null) !== $key) {
                    $this->violate('B1', 'medium', $sym, 'bot entered ' . $state->position_state . ' (' . $state->block_reason_code . ')', ['detail' => mb_substr((string) $state->block_reason_detail, 0, 240), 'held_qty' => $held]);
                    $this->symState[$sym]['last_block'] = $key;
                }
            } else {
                unset($this->symState[$sym]['last_block']);
            }
            if ($state->position_state === 'awaiting_execution_result') {
                $this->m($sym, 'ticks_awaiting');
            }
        }

        // lots (v2)
        if ($cfg->v2_enabled && ($newTrades !== [] || ($this->h->ticks % 288) === 0)) {
            $this->auditLots($sym, $cfg);
        }
    }

    private function pct(mixed $v): float
    {
        $p = (float) ($v ?? 0);
        if ($p <= 0) {
            return 0.0;
        }

        return $p > 1 ? $p / 100 : $p; // mirrors RatioBotDecisionService::ratioFromConfigPct (documented quirk)
    }

    private function auditTrigger(string $sym, $cfg, RatioBotDecision $d, array $meta, string $trigger, ?array $preState, float $costRatio, float $sl, float $tp, float $sellTh, float $buyTh): void
    {
        $this->cnt('S1');
        $this->m($sym, 'trigger_' . ($trigger ?: 'untagged'));
        $price = (float) $d->evaluated_price;
        $anchor = (float) $d->anchor_price;
        $ratio = $anchor > 0 ? $price / $anchor - 1 : null;
        $eps = 1e-6;
        $fail = null;
        switch ($trigger) {
            case 'stop_loss':
            case 'capital_protection_stop_loss':
                $a = $preState['last_buy_price'] ?? $anchor;
                $r = $a > 0 ? $price / $a - 1 : 0;
                if (! ($sl > 0 && $r <= -$sl + $eps)) {
                    $fail = "stop-loss fired at ratio {$r} vs required <= -{$sl}";
                }
                break;
            case 'take_profit':
                $r = $anchor > 0 ? $price / $anchor - 1 : 0;
                if (! ($tp > 0 && $r >= $tp - $eps)) {
                    $fail = "take-profit fired at ratio {$r} vs required >= {$tp}";
                }
                break;
            case 'time_stop':
                $mins = (int) $cfg->time_stop_minutes;
                $last = $preState['last_trade_time'] ?? null;
                if (! ($mins > 0 && $last && Carbon::parse($last)->addMinutes($mins)->lte(now()))) {
                    $fail = "time-stop fired without {$mins} min elapsed";
                }
                break;
            case 'sell_threshold':
                if ($ratio !== null && $ratio < $sellTh + $costRatio - $eps) {
                    $fail = "sell fired at ratio {$ratio} < {$sellTh}+cost {$costRatio}";
                }
                break;
            case 'buy_threshold':
                $rr = $price > 0 ? $anchor / $price - 1 : 0;
                if ($rr < $buyTh + $costRatio - $eps) {
                    $fail = "buy fired at drop {$rr} < {$buyTh}+cost {$costRatio}";
                }
                break;
            case 'stage_fifo_lot_sell':
                $min = (float) data_get($meta, 'minimum_net_profit_ratio', 0);
                $mb = (float) data_get($meta, 'sell_plan.min_buy_price', 0) ?: $anchor;
                if ($mb > 0 && $price < $mb * (1 + $min) - $eps) {
                    $fail = "stage sell at {$price} below lot floor " . $mb * (1 + $min);
                }
                break;
        }
        if ($fail !== null) {
            $this->violate('S1', 'high', $sym, $fail, ['trigger' => $trigger, 'price' => $price, 'anchor' => $anchor, 'decision' => $d->decision]);
        }
        // arithmetic cross-check of the recorded ratio
        if ($ratio !== null && $d->computed_ratio !== null && in_array($trigger, ['sell_threshold', 'stop_loss', 'take_profit', 'time_stop'], true)) {
            $this->cnt('S2');
            if (abs((float) $d->computed_ratio - $ratio) > 1e-5) {
                $this->violate('S2', 'medium', $sym, 'recorded ratio differs from price/anchor-1', ['recorded' => (float) $d->computed_ratio, 'recomputed' => $ratio]);
            }
        }
    }

    private function auditTrade(string $sym, $cfg, ?RatioBotState $state, array $t, ?array $preState, array $pre): void
    {
        $ex = $this->h->exchange;
        $rule = $this->rules($sym);
        $base = $rule['base'];
        $side = $t['side'];
        $price = $t['price'];
        $qty = FakeBinanceSpot::toFloat($t['qty']);
        $quote = FakeBinanceSpot::toFloat($t['quoteQty']);
        $feeUnits = $t['commission'];
        $feeAsset = $t['commissionAsset'];
        $feeUsdt = $feeAsset === 'USDT' ? FakeBinanceSpot::toFloat($feeUnits) : ($feeAsset === 'BNB' ? FakeBinanceSpot::toFloat($feeUnits) * $ex->bnbPrice : FakeBinanceSpot::toFloat($feeUnits) * $price);

        $this->m($sym, $side === 'BUY' ? 'buys' : 'sells');
        $this->m($sym, $side === 'BUY' ? 'usdt_to_base_notional' : 'base_to_usdt_notional', $quote);
        $this->m($sym, $side === 'BUY' ? 'usdt_to_base_count' : 'base_to_usdt_count');
        $this->m($sym, 'fees_usdt', $feeUsdt);
        $this->chunkTrades[] = ['t' => now()->toIso8601String(), 'symbol' => $sym, 'side' => $side, 'price' => $price, 'qty' => $qty, 'quote' => $quote, 'fee' => FakeBinanceSpot::toFloat($feeUnits), 'fee_asset' => $feeAsset, 'fee_usdt' => $feeUsdt, 'order_id' => $t['orderId'], 'client_id' => $t['clientOrderId']];

        // X1 filters (independent of the exchange's own enforcement)
        $this->cnt('X1');
        if ($t['qty'] % $rule['step'] !== 0 || $t['qty'] < $rule['minQty'] || $t['quoteQty'] < $rule['minNotional'] - 5) {
            $this->violate('X1', 'critical', $sym, 'executed trade violates symbol filters', ['qty' => $qty, 'quote' => $quote, 'rules' => $rule]);
        }

        // F1 fee charged at the documented rate
        $this->cnt('F1');
        $rate = $feeAsset === 'BNB' ? 0.00075 : 0.001;
        $notionalUnits = $feeAsset === $base ? $t['qty'] : ($feeAsset === 'BNB' ? $t['quoteQty'] / max($ex->bnbPrice, 1e-9) : $t['quoteQty']);
        if ($feeUnits <= 0) {
            $this->violate('F1', 'high', $sym, 'fill carried no commission', ['trade' => $t]);
        } elseif ($feeAsset !== 'BNB' && abs($feeUnits - $notionalUnits * $rate) > 1.0) {
            $this->violate('F1', 'high', $sym, 'commission differs from 0.10% of the fill beyond rounding', ['fee' => $feeUnits, 'expected' => $notionalUnits * $rate]);
        }

        // R1 / R2: DB order + fill
        $this->cnt('R1');
        $orders = Order::where('exchange_order_id', (string) $t['orderId'])->get();
        if ($orders->count() !== 1) {
            $this->violate('R1', 'critical', $sym, 'exchange trade has ' . $orders->count() . ' DB orders (expected exactly 1)', ['trade' => $t]);

            return;
        }
        $order = $orders->first();
        $fills = OrderFill::where('order_id', $order->id)->where('exchange_trade_id', (string) $t['tradeId'])->get();
        if ($fills->count() !== 1) {
            $this->violate('R1', 'critical', $sym, 'exchange trade has ' . $fills->count() . ' DB fills (expected exactly 1)', ['trade' => $t]);
        } else {
            $this->cnt('R2');
            $f = $fills->first();
            if (abs((float) $f->fill_price - $price) > 1e-8 || abs((float) $f->fill_qty - $qty) > 1e-8 || abs((float) $f->fee - FakeBinanceSpot::toFloat($feeUnits)) > 1e-8 || strtoupper((string) $f->fee_asset) !== $feeAsset) {
                $this->violate('R2', 'high', $sym, 'DB fill differs from exchange trade', ['db' => ['p' => (float) $f->fill_price, 'q' => (float) $f->fill_qty, 'fee' => (float) $f->fee, 'asset' => $f->fee_asset], 'ex' => ['p' => $price, 'q' => $qty, 'fee' => FakeBinanceSpot::toFloat($feeUnits), 'asset' => $feeAsset]]);
            }
        }
        if (strtoupper((string) $order->status) !== 'FILLED') {
            $this->violate('R3', 'high', $sym, 'DB order status ' . $order->status . ' for a filled exchange order', ['order_id' => $order->id]);
        }

        // D1: decision <-> order
        $this->cnt('D1');
        $decs = RatioBotDecision::where('ratio_bot_config_id', $cfg->id)->where('client_order_id', $t['clientOrderId'])->get();
        if ($decs->count() !== 1) {
            $this->violate('D1', 'critical', $sym, 'client order id maps to ' . $decs->count() . ' decisions (expected exactly 1)', ['client_id' => $t['clientOrderId'], 'trade' => $t]);

            return;
        }
        $d = $decs->first();
        if ($d->execution_status !== RatioBotDecision::EXEC_FILLED) {
            $this->violate('D1', 'high', $sym, 'decision for a filled order has status ' . $d->execution_status, ['decision' => (string) $d->id]);
        }
        $expectedQty = ($side === 'BUY' && $feeAsset === $base) ? $qty - FakeBinanceSpot::toFloat($feeUnits) : $qty;
        if (abs((float) $d->executed_qty - $expectedQty) > 2e-8 || abs((float) $d->executed_price - $price) > 1e-6) {
            $this->violate('D1', 'high', $sym, 'bot booked different qty/price than the exchange filled (net of base-asset fee)', ['booked_qty' => (float) $d->executed_qty, 'expected_qty' => $expectedQty, 'booked_px' => (float) $d->executed_price, 'px' => $price]);
        }
        $this->cnt('D2');
        if (RatioBotDecision::where('order_id', (string) $t['orderId'])->count() > 1) {
            $this->violate('D2', 'critical', $sym, 'two decisions share one exchange order', ['order' => $t['orderId']]);
        }
        // decision side agrees with order side
        if (strtoupper($d->decision) !== $side) {
            $this->violate('D1', 'critical', $sym, 'decision side differs from executed order side', ['decision' => $d->decision, 'side' => $side]);
        }

        // size sanity (BUY): never spends more than the decision's stated quote amount (+ one step)
        $meta = (array) ($d->metadata ?? []);
        $this->cnt('O1');
        if ($side === 'BUY') {
            $cap = (float) ($meta['stage_quote_amount'] ?? $cfg->base_quote_amount);
            if ($cap > 0 && $quote > $cap * 1.0005 + 1) {
                $this->violate('O1', 'high', $sym, 'BUY spent more quote than the decision/config allows', ['spent' => $quote, 'cap' => $cap]);
            }
        }

        // C1: no fill during cooldown
        $this->cnt('C1');
        $cd = $preState['cooldown_until'] ?? null;
        if ($cd && Carbon::parse($cd)->gt(now()) && empty($meta['protective_exit'])) {
            $this->violate('C1', 'medium', $sym, 'order executed while the post-trade cooldown was still running', ['cooldown_until' => (string) $cd, 'now' => now()->toIso8601String(), 'trigger' => $meta['trigger'] ?? null]);
        }

        // Independent average-cost ledger + realized P&L (P1)
        $this->ledgerApply($sym, $side, $qty, $quote, $feeAsset, $feeUnits, $feeUsdt, $price, $base);

        // G1 profit guard on non-protective sells
        if ($side === 'SELL') {
            $this->cnt('G1');
            $protective = ! empty($meta['protective_exit']);
            $this->m($sym, $protective ? 'sells_protective' : 'sells_profit_taking');
            if (! $protective) {
                $lotIds = (array) ($meta['sell_lot_ids'] ?? []);
                $minNet = (float) ($meta['minimum_net_profit_ratio'] ?? 0);
                $decisionPrice = (float) $d->evaluated_price;
                foreach ($lotIds as $lid) {
                    $lot = RatioBotLot::find($lid);
                    if (! $lot) {
                        continue;
                    }
                    if ($decisionPrice < (float) $lot->buy_price * (1 + $minNet) - 1e-6) {
                        $this->violate('G1', 'critical', $sym, 'profit guard breached: lot sold below its own floor at decision time', ['lot' => $lid, 'buy_price' => (float) $lot->buy_price, 'decision_price' => $decisionPrice, 'minNet' => $minNet]);
                    }
                    $cost = ((float) $cfg->fee_bps * 2) / 10000;
                    if ($price < (float) $lot->buy_price * (1 + $cost)) {
                        $this->m($sym, 'sells_net_loss_after_fill_gap');
                        $this->note('fill_below_fee_breakeven', $sym, ['lot_buy' => (float) $lot->buy_price, 'decision_price' => $decisionPrice, 'fill_price' => $price]);
                    }
                }
                if ($lotIds === [] && ! $cfg->v2_enabled) {
                    $entry = (float) ($preState['last_buy_price'] ?? 0);
                    if ($entry > 0 && $decisionPrice < $entry * (1 + ((float) $cfg->min_net_profit_bps / 10000)) - 1e-6) {
                        $this->violate('G1', 'high', $sym, 'non-protective sell below entry + minimum net profit (classic path)', ['entry' => $entry, 'decision_price' => $decisionPrice, 'trigger' => $meta['trigger'] ?? null]);
                    }
                }
            }
        }
    }

    private function ledgerApply(string $sym, string $side, float $qty, float $quote, string $feeAsset, int $feeUnits, float $feeUsdt, float $price, string $base): void
    {
        $L = &$this->ledger[$sym];
        if (! isset($L)) {
            $init = FakeBinanceSpot::toFloat($this->startBalances[$base] ?? 0);
            $L = ['qty' => $init, 'cost' => $init * $price, 'realized' => 0.0, 'wins' => 0, 'losses' => 0, 'init_qty' => $init];
        }
        $feeBase = $feeAsset === $base ? FakeBinanceSpot::toFloat($feeUnits) : 0.0;
        if ($side === 'BUY') {
            $L['cost'] += $quote + ($feeAsset === $base ? 0.0 : $feeUsdt);
            $L['qty'] += $qty - $feeBase;
        } else {
            $avg = $L['qty'] > 0 ? $L['cost'] / $L['qty'] : $price;
            $proceeds = $quote - ($feeAsset === $base ? 0.0 : $feeUsdt);
            $pnl = $proceeds - $avg * $qty;
            $L['realized'] += $pnl;
            $L['cost'] -= $avg * $qty;
            $L['qty'] -= $qty;
            if ($L['qty'] < 0) {
                $L['qty'] = 0.0;
                $L['cost'] = 0.0;
            }
            $this->m($sym, 'realized_pnl_indep', $pnl);
            $this->m($sym, $pnl > 0 ? 'sell_wins' : 'sell_losses');
        }
    }

    private function auditAnchor(string $sym, $cfg, RatioBotState $state, array $pre, array $newTrades, $decisions): void
    {
        $this->cnt('A1');
        $buys = array_values(array_filter($newTrades, fn ($t) => $t['side'] === 'BUY'));
        $sells = array_values(array_filter($newTrades, fn ($t) => $t['side'] === 'SELL'));
        $isHolding = in_array($pre['position_state'], ['holding', 'partial_holding', 'partial_sold'], true);
        $reanchored = RatioBotEvent::where('ratio_bot_config_id', $cfg->id)->where('occurred_at', '>=', now()->format('Y-m-d H:i:s'))->whereIn('event_type', ['strategy_reanchored', 'manual_buy_anchored', 'manual_sell_anchored'])->exists();

        // cost basis immobile while holding with no new BUY and no SELL
        if ($isHolding && $buys === [] && $sells === [] && $pre['last_buy_price'] > 0 && ! $reanchored) {
            if (abs((float) $state->last_buy_price - $pre['last_buy_price']) > 1e-8) {
                $this->violate('A1', 'high', $sym, 'cost-basis anchor moved while holding with no fill', ['before' => $pre['last_buy_price'], 'after' => (float) $state->last_buy_price]);
            }
        }
        // after a BUY (fresh entry) anchor == executed price
        foreach ($buys as $b) {
            if ($pre['position_state'] === 'flat' || $pre['position_state'] === 'idle') {
                if (! $cfg->v2_enabled && abs((float) $state->last_buy_price - $b['price']) > 1e-6) {
                    $this->violate('A1', 'high', $sym, 'after a fresh BUY the anchor is not the executed price', ['anchor' => (float) $state->last_buy_price, 'fill' => $b['price']]);
                }
            }
        }
        // after a full SELL (flat) the sell anchor == executed price and cost basis cleared
        if ($sells !== [] && $state->position_state === 'flat') {
            $last = end($sells);
            if (abs((float) $state->last_sell_price - $last['price']) > 1e-6) {
                $this->violate('A1', 'high', $sym, 'after a full exit the sell anchor is not the executed price', ['anchor' => (float) $state->last_sell_price, 'fill' => $last['price']]);
            }
            if ((float) $state->last_buy_price != 0.0 || (float) $state->last_buy_quantity != 0.0) {
                $this->violate('A1', 'high', $sym, 'cost basis not cleared after full exit', ['last_buy_price' => (float) $state->last_buy_price, 'qty' => (float) $state->last_buy_quantity]);
            }
        }
        // flat: sell anchor immobile with no fill
        if ($pre['position_state'] === 'flat' && $buys === [] && $sells === [] && $pre['last_sell_price'] > 0 && ! $reanchored) {
            if (abs((float) $state->last_sell_price - $pre['last_sell_price']) > 1e-8 && ! $cfg->v2_enabled) {
                $this->violate('A1', 'medium', $sym, 'sell anchor moved while flat with no fill', ['before' => $pre['last_sell_price'], 'after' => (float) $state->last_sell_price]);
            }
        }
    }

    private function auditLots(string $sym, $cfg): void
    {
        $ex = $this->h->exchange;
        $rule = $this->rules($sym);
        $this->cnt('Q1');
        $walletBase = FakeBinanceSpot::toFloat($ex->freeOf($rule['base']));
        $sumOpen = 0.0;
        foreach (RatioBotLot::where('ratio_bot_config_id', $cfg->id)->get() as $lot) {
            $orig = (float) $lot->original_qty;
            $rem = (float) $lot->remaining_qty;
            $sold = collect((array) $lot->sell_fills)->sum(fn ($f) => (float) ($f['qty'] ?? 0));
            if ($rem < -1e-9 || $rem > $orig + 1e-9) {
                $this->violate('Q1', 'critical', $sym, 'lot remaining_qty outside [0, original]', ['lot' => (string) $lot->id, 'orig' => $orig, 'rem' => $rem]);
            }
            if (abs($orig - $rem - $sold) > 1e-7 && $lot->status === RatioBotLot::STATUS_OPEN) {
                $this->violate('Q1', 'high', $sym, 'lot sold quantity + remaining != original', ['lot' => (string) $lot->id, 'orig' => $orig, 'rem' => $rem, 'sold' => $sold]);
            }
            if ($lot->status === RatioBotLot::STATUS_OPEN) {
                $sumOpen += $rem;
            }
        }
        $this->cnt('Q2');
        if ($sumOpen > $walletBase + 1e-7) {
            $this->violate('Q2', 'critical', $sym, 'open lots exceed the wallet base balance (sold quantity would exceed held)', ['lots' => $sumOpen, 'wallet' => $walletBase]);
        }
    }

    // ------------------------------------------------------------------ month flush / persistence

    /** Month-end hook: add DB-derived counters, write chunk files, return the monthly table rows. */
    public function flushMonth(string $ym): void
    {
        $this->ym = $ym;
        foreach ($this->h->symbols as $sym) {
            $cfg = $this->h->configs[$sym]->fresh('state');
            $st = $cfg->state;
            $realized = (float) ($st->realized_pnl ?? 0);
            $this->monthly[$sym][$ym]['realized_pnl_bot'] = $realized - ($this->botRealizedAtMonthStart[$sym] ?? 0.0);
            $this->botRealizedAtMonthStart[$sym] = $realized;
            $this->monthly[$sym][$ym]['price_end'] = $this->price($sym);
            $this->monthly[$sym][$ym]['position_end'] = $st->position_state;
            $L = $this->ledger[$sym] ?? null;
            $this->monthly[$sym][$ym]['unrealized_end'] = $L ? ($L['qty'] * $this->price($sym) - $L['cost']) : 0.0;
        }
        $dir = $this->outDir;
        $fh = fopen("{$dir}/trades_{$this->runId}.jsonl", 'a');
        foreach ($this->chunkTrades as $r) {
            fwrite($fh, json_encode($r) . "\n");
        }
        fclose($fh);
        $this->chunkTrades = [];
        $fh = fopen("{$dir}/equity_hourly_{$this->runId}.jsonl", 'a');
        foreach ($this->chunkEquity as $r) {
            fwrite($fh, json_encode($r) . "\n");
        }
        fclose($fh);
        $this->chunkEquity = [];
    }

    public function exportState(): array
    {
        return [
            'anomalies' => $this->anomalies, 'anomalyCounts' => $this->anomalyCounts, 'notes' => $this->notes, 'checks' => $this->checks,
            'monthly' => $this->monthly, 'decisionStats' => $this->decisionStats, 'ledger' => $this->ledger, 'equityPeak' => $this->equityPeak,
            'maxDrawdown' => $this->maxDrawdown, 'startEquity' => $this->startEquity, 'startBalances' => $this->startBalances,
            'botRealizedAtMonthStart' => $this->botRealizedAtMonthStart, 'tradeCursor' => $this->tradeCursor, 'rejectionCursor' => $this->rejectionCursor,
        ];
    }

    public function importState(array $s): void
    {
        foreach ($s as $k => $v) {
            $this->{$k} = $v;
        }
    }

    public function summary(): array
    {
        return [
            'checks' => $this->checks, 'anomaly_counts' => $this->anomalyCounts, 'max_drawdown' => $this->maxDrawdown,
            'start_equity' => $this->startEquity, 'end_equity' => $this->equity(),
        ];
    }
}
