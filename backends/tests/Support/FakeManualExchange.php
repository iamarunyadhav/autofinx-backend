<?php

namespace Tests\Support;

use App\Domain\QuickTradeManual\Exchange\BinanceAlgoApi;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeException;
use App\Domain\QuickTradeManual\Exchange\ManualExchangeGateway;

/**
 * In-memory Binance futures for Manual SL/TP tests. It is a MODEL of the exchange as this
 * code believes it works (including the UNVERIFIED algo-order API), so tests prove our logic,
 * not Binance's behaviour. Programmable failures + a call log for idempotency assertions.
 */
class FakeManualExchange implements ManualExchangeGateway
{
    public string $env = 'testnet';
    public bool $hedge = false;
    public float $mark = 80000.0;
    public int $markTimeOffsetMs = 0;
    public float $tick = 0.1;
    public float $step = 0.001;
    public float $minQty = 0.001;
    public float $minNotional = 100.0;
    public float $balance = 100000.0;
    public ?array $brackets = null;
    public float $fundingRate = 0.0001;

    /** signed position amount, entry price, liquidation price, margin type, leverage */
    public float $amount = 0.0;
    public float $entryPrice = 0.0;
    public ?float $liqPrice = null;
    public string $marginType = 'isolated';
    public float $leverage = 10.0;

    public float $fillSlippage = 0.0;       // absolute price offset on entry fills
    public float $fillRatio = 1.0;          // partial fill ratio for entries

    /** @var array<string, array> orders by client id */
    public array $orders = [];
    /** @var array<string, array> algo orders by algoId */
    public array $algos = [];
    /** @var list<array> */
    public array $trades = [];
    public array $calls = [];
    private int $seq = 1000;

    // failure injection
    public ?ManualExchangeException $entryError = null;
    public bool $entryLostAck = false;            // order fills but the call throws ambiguous
    public ?ManualExchangeException $protectiveError = null;
    public bool $protectiveAckOnlyUnverifiable = false;   // placement ok, queries return null
    public ?string $protectiveStatus = null;     // override status in queries
    public bool $tpRejected = false;
    public ?ManualExchangeException $closeError = null;
    public bool $closeNoEffect = false;           // close order "accepted" but position stays open
    public bool $closeLostAck = false;
    public bool $readsFail = false;
    public ?float $fundingIncome = 0.0;
    public ?bool $liquidated = false;
    public bool $fillsUnavailable = false;
    public ?\Closure $beforeClose = null;        // runs at the start of placeClose (simulate an SL firing mid-close)

    public function __construct()
    {
        $this->brackets = [
            ['bracket' => 1, 'initialLeverage' => 125, 'notionalFloor' => 0, 'notionalCap' => 50000, 'maintMarginRatio' => 0.004],
            ['bracket' => 2, 'initialLeverage' => 100, 'notionalFloor' => 50000, 'notionalCap' => 250000, 'maintMarginRatio' => 0.005],
        ];
    }

    public function count(string $method): int
    {
        return count(array_filter($this->calls, fn ($c) => $c[0] === $method));
    }

    public function environment(): string
    {
        return $this->env;
    }

    public function isHedgeMode(): bool
    {
        $this->log('isHedgeMode');
        $this->maybeFailRead();

        return $this->hedge;
    }

    public function symbolRules(string $symbol): array
    {
        $this->log('symbolRules');
        $this->maybeFailRead();

        return ['tick_size' => $this->tick, 'step_size' => $this->step, 'min_qty' => $this->minQty, 'min_notional' => $this->minNotional];
    }

    public function markPrice(string $symbol): array
    {
        $this->log('markPrice');
        $this->maybeFailRead();

        return ['mark_price' => $this->mark, 'time_ms' => (int) floor(microtime(true) * 1000) + $this->markTimeOffsetMs, 'funding_rate' => $this->fundingRate, 'next_funding_ms' => (int) floor(microtime(true) * 1000) + 3600000];
    }

    public function availableBalance(): float
    {
        $this->log('availableBalance');
        $this->maybeFailRead();

        return $this->balance;
    }

    public function leverageBrackets(string $symbol): ?array
    {
        return $this->brackets;
    }

    public function position(string $symbol, ?string $positionSide): array
    {
        $this->log('position');
        $this->maybeFailRead();

        return [
            'amount' => $this->amount,
            'entry_price' => $this->entryPrice,
            'mark_price' => $this->mark,
            'liquidation_price' => abs($this->amount) > 0 ? $this->liqPrice : null,
            'leverage' => $this->leverage,
            'margin_type' => $this->marginType,
            'unrealized_pnl' => abs($this->amount) > 0 ? ($this->mark - $this->entryPrice) * $this->amount : 0.0,
        ];
    }

    public function setLeverage(string $symbol, int $leverage): void
    {
        $this->log('setLeverage', $leverage);
        $this->leverage = (float) $leverage;
    }

    public function placeEntry(array $p): array
    {
        $this->log('placeEntry', $p);
        if ($this->entryError && ! $this->entryLostAck) {
            throw $this->entryError;
        }
        $qty = round((float) $p['quantity'] * $this->fillRatio, 8);
        $buy = strtoupper($p['side']) === 'BUY';
        $price = $this->mark + ($buy ? $this->fillSlippage : -$this->fillSlippage);
        $order = $this->fill($p['client_order_id'], strtoupper($p['side']), $qty, $price, 0.0);
        $this->amount += $buy ? $qty : -$qty;
        $this->entryPrice = $price;
        if ($this->fillRatio < 1.0) {
            $order['status'] = 'PARTIALLY_FILLED';
            $this->orders[$p['client_order_id']] = $order;
        }
        if ($this->entryLostAck) {
            throw ManualExchangeException::unknownOutcome('timeout waiting for response');
        }

        return $order;
    }

    public function placeClose(array $p): array
    {
        $this->log('placeClose', $p);
        if ($this->beforeClose) {
            ($this->beforeClose)($this);
            $this->beforeClose = null;
        }
        if ($this->closeError) {
            throw $this->closeError;
        }
        if (abs($this->amount) <= 0.0) {
            throw ManualExchangeException::rejected('ReduceOnly Order is rejected.', -2022);
        }
        if ($this->closeNoEffect) {
            return ['order_id' => (string) ++$this->seq, 'client_order_id' => $p['client_order_id'], 'status' => 'NEW', 'executed_qty' => 0.0, 'avg_price' => 0.0];
        }
        $qty = min(abs($this->amount), (float) $p['quantity']);
        $sell = strtoupper($p['side']) === 'SELL';
        $realized = ($this->mark - $this->entryPrice) * $qty * ($this->amount > 0 ? 1 : -1);
        $order = $this->fill($p['client_order_id'], strtoupper($p['side']), $qty, $this->mark, $realized);
        $this->amount += $sell ? -$qty : $qty;
        if (abs($this->amount) < 1e-12) {
            $this->amount = 0.0;
        }
        if ($this->closeLostAck) {
            throw ManualExchangeException::unknownOutcome('timeout waiting for response');
        }

        return $order;
    }

    public function queryOrder(string $symbol, ?string $orderId, ?string $clientOrderId): ?array
    {
        $this->log('queryOrder', [$orderId, $clientOrderId]);
        $this->maybeFailRead();
        foreach ($this->orders as $cid => $o) {
            if (($clientOrderId && $cid === $clientOrderId) || ($orderId && $o['order_id'] === $orderId)) {
                return $o;
            }
        }

        return null;
    }

    public function placeProtective(array $p): array
    {
        $this->log('placeProtective', $p);
        if ($this->protectiveError) {
            throw $this->protectiveError;
        }
        if ($p['type'] === BinanceAlgoApi::TYPE_TAKE_PROFIT && $this->tpRejected) {
            throw ManualExchangeException::rejected('TP rejected', -4000);
        }
        foreach ($this->algos as $a) {
            if ($a['client_algo_id'] === $p['client_algo_id']) {
                throw ManualExchangeException::rejected('Duplicate client algo id', -4015);
            }
        }
        $id = (string) ++$this->seq;
        $this->algos[$id] = [
            'algo_id' => $id,
            'client_algo_id' => $p['client_algo_id'],
            'status' => 'NEW',
            'symbol' => strtoupper($p['symbol']),
            'side' => strtoupper($p['side']),
            'type' => $p['type'],
            'trigger_price' => (float) $p['trigger_price'],
            'close_position' => true,
            'position_side' => $p['position_side'],
        ];

        return ['algo_id' => $id, 'client_algo_id' => $p['client_algo_id'], 'status' => 'NEW'];
    }

    public function queryProtective(string $symbol, ?string $algoId, ?string $clientAlgoId): ?array
    {
        $this->log('queryProtective', [$algoId, $clientAlgoId]);
        $this->maybeFailRead();
        if ($this->protectiveAckOnlyUnverifiable) {
            return null;
        }
        foreach ($this->algos as $a) {
            if (($algoId && $a['algo_id'] === $algoId) || ($clientAlgoId && $a['client_algo_id'] === $clientAlgoId)) {
                if ($this->protectiveStatus !== null) {
                    $a['status'] = $this->protectiveStatus;
                }

                return $a;
            }
        }

        return null;
    }

    public function cancelProtective(string $symbol, ?string $algoId, ?string $clientAlgoId): void
    {
        $this->log('cancelProtective', [$algoId, $clientAlgoId]);
        foreach ($this->algos as $id => $a) {
            if (($algoId && (string) $id === $algoId) || ($clientAlgoId && $a['client_algo_id'] === $clientAlgoId)) {
                $this->algos[$id]['status'] = 'CANCELED';
            }
        }
    }

    public function orderFills(string $symbol, string $orderId): ?array
    {
        if ($this->fillsUnavailable) {
            return null;
        }
        $rows = array_values(array_filter($this->trades, fn ($t) => $t['order_id'] === $orderId));
        if ($rows === []) {
            return null;
        }
        $q = array_sum(array_column($rows, 'quantity'));
        $n = array_sum(array_map(fn ($t) => $t['quantity'] * $t['price'], $rows));

        return ['commission' => round(array_sum(array_column($rows, 'commission')), 8), 'realized_pnl' => round(array_sum(array_column($rows, 'realized_pnl')), 8), 'quantity' => $q, 'avg_price' => $n / $q, 'fills' => count($rows)];
    }

    public function fundingIncome(string $symbol, int $fromMs, int $toMs): ?float
    {
        return $this->fundingIncome;
    }

    public function liquidationSince(string $symbol, int $sinceMs): ?bool
    {
        return $this->liquidated;
    }

    public function tradesSince(string $symbol, int $sinceMs): ?array
    {
        return $this->fillsUnavailable ? null : $this->trades;
    }

    // ------------------------------------------------ test helpers

    /** Simulate the exchange firing the SL/TP algo order: position flat, algo TRIGGERED, a closing fill exists. */
    public function triggerAlgo(string $type, float $price): void
    {
        foreach ($this->algos as $id => $a) {
            if ($a['type'] === $type && $a['status'] === 'NEW') {
                $this->algos[$id]['status'] = 'TRIGGERED';
                $sell = $this->amount > 0;
                $qty = abs($this->amount);
                $realized = ($price - $this->entryPrice) * $qty * ($this->amount > 0 ? 1 : -1);
                $this->fill('exch-'.$id, $sell ? 'SELL' : 'BUY', $qty, $price, $realized);
                $this->amount = 0.0;
                // sibling auto-cancel is NOT assumed: the code must clean it up itself.
                return;
            }
        }
    }

    public function externalClose(float $price): void
    {
        $sell = $this->amount > 0;
        $qty = abs($this->amount);
        $realized = ($price - $this->entryPrice) * $qty * ($this->amount > 0 ? 1 : -1);
        $this->fill('ext-'.(++$this->seq), $sell ? 'SELL' : 'BUY', $qty, $price, $realized);
        $this->amount = 0.0;
    }

    public function liveAlgos(): array
    {
        return array_values(array_filter($this->algos, fn ($a) => in_array($a['status'], BinanceAlgoApi::WORKING_STATUSES, true)));
    }

    private function fill(string $clientId, string $side, float $qty, float $price, float $realized): array
    {
        $orderId = (string) ++$this->seq;
        $order = ['order_id' => $orderId, 'client_order_id' => $clientId, 'status' => 'FILLED', 'executed_qty' => $qty, 'avg_price' => $price];
        $this->orders[$clientId] = $order;
        $this->trades[] = [
            'order_id' => $orderId,
            'side' => $side,
            'price' => $price,
            'quantity' => $qty,
            'commission' => round($qty * $price * 0.0005, 8),
            'realized_pnl' => round($realized, 8),
            'time_ms' => (int) floor(microtime(true) * 1000),
        ];

        return $order;
    }

    private function log(string $m, mixed $arg = null): void
    {
        $this->calls[] = [$m, $arg];
    }

    private function maybeFailRead(): void
    {
        if ($this->readsFail) {
            throw ManualExchangeException::unknownOutcome('simulated exchange read failure');
        }
    }
}
