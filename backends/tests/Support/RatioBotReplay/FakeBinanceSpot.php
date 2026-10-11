<?php

namespace Tests\Support\RatioBotReplay;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Http;

/**
 * Stateful in-memory model of the Binance SPOT REST API, served through
 * Http::fake() so the REAL production adapters (BinanceSpotClient,
 * BinanceTradingVenueClient, LiveOrderIntentService, ReconciliationService...)
 * run unmodified against it.
 *
 * All money is held as integers in 1e-8 units ("sats"), so value conservation
 * can be asserted EXACTLY (no float drift in the audit ledger itself).
 *
 * Fill model (documented, conservative, deterministic):
 *  - MARKET orders fill at the OPEN of the next 1m bar (`nextOpen`, set by the
 *    harness before each tick) -- i.e. one bar of latency, gaps included.
 *  - Taker fee 0.10% (or 0.075% paid in BNB for the BNB-discount variant).
 *    Standard fee asset = the received asset (BUY -> base, SELL -> quote),
 *    like the real exchange. Commission rounded half-up to 8 dp.
 *  - Filters (tick/step/minQty/minNotional) are enforced INDEPENDENTLY of the
 *    bot: a violating order is answered with HTTP 400 / -1013 and never fills.
 *  - Insufficient free balance -> HTTP 400 / -2010. Balances can never go negative.
 *  - A reused newClientOrderId is accepted after the earlier order is no longer
 *    open (real Binance behaviour) -- and recorded in `$duplicateClientIdFills`
 *    so the audit can flag a double submission the exchange could not stop.
 */
class FakeBinanceSpot
{
    public const SCALE = 100000000; // 1e8

    /** @var array<string, array{base:string, quote:string, tick:int, step:int, minQty:int, minNotional:int, maxQty:int}> */
    public array $symbols = [];

    /** @var array<string, int> asset => free (1e-8 units) */
    public array $free = [];

    /** @var array<string, int> */
    public array $locked = [];

    /** @var array<string, float> latest close per symbol (what ticker/price returns) */
    public array $lastPrice = [];

    /** @var array<string, float> price the next MARKET order fills at, per symbol */
    public array $nextOpen = [];

    /** @var array<string, float> next-bar high/low for realism checks (informational) */
    public array $nextRange = [];

    public float $bnbPrice = 600.0;

    /** BNB is valued at a fixed book price in equity so BNB price noise never pollutes trading P&L (fees paid in BNB still reduce equity). */
    public ?float $bnbBookPrice = null;

    /** 'std' = 0.10% in received asset; 'bnb' = 0.075% paid from the BNB balance */
    public string $feeMode = 'std';

    public int $nowMs = 0;

    /** @var array<int, array> orders by orderId */
    public array $orders = [];

    /** @var array<string, int> clientOrderId => orderId (latest) */
    public array $clientIndex = [];

    /** @var list<array> every executed trade (exchange-side audit log) */
    public array $trades = [];

    /** @var list<array> every request received (method, path, params summary) */
    public array $calls = [];

    /** @var list<string> */
    public array $unhandled = [];

    public int $duplicateClientIdFills = 0;

    /** @var list<array> orders rejected by the exchange with reason (audit) */
    public array $rejections = [];

    private int $orderSeq = 100000;
    private int $tradeSeq = 5000000;

    // ---- fault injection (one-shot unless noted) ----
    /** 'http500_before' = 500, no order placed; 'http500_after' = order FILLS but the response is a 500 (lost ack); 'timeout1007' = -1007 body, order filled; 'partial' = EXPIRED with partial fill; 'reject_notional' = -1013 NOTIONAL; 'insufficient' = -2010 */
    public ?string $failNextOrder = null;
    public float $partialRatio = 0.5;
    /** when true, GET ticker/price answers 500 (price endpoint down) */
    public bool $tickerDown = false;
    /** when true, every account (balance) call answers 500 */
    public bool $accountDown = false;

    public function __construct()
    {
        $this->addSymbol('BTCUSDT', 'BTC', 'USDT', tick: '0.01', step: '0.00001', minQty: '0.00001', minNotional: '5', maxQty: '9000');
        $this->addSymbol('ETHUSDT', 'ETH', 'USDT', tick: '0.01', step: '0.0001', minQty: '0.0001', minNotional: '5', maxQty: '9000');
    }

    public function addSymbol(string $symbol, string $base, string $quote, string $tick, string $step, string $minQty, string $minNotional, string $maxQty): void
    {
        $this->symbols[$symbol] = [
            'base' => $base, 'quote' => $quote,
            'tick' => self::units($tick), 'step' => self::units($step), 'minQty' => self::units($minQty),
            'minNotional' => self::units($minNotional), 'maxQty' => self::units($maxQty),
        ];
    }

    // ------------------------------------------------------------------ money helpers

    public static function units(string|float|int $value): int
    {
        // string-safe conversion (avoid float artifacts such as 0.00001 * 1e8 = 999.99999)
        $s = is_string($value) ? $value : rtrim(rtrim(number_format((float) $value, 12, '.', ''), '0'), '.');
        $neg = str_starts_with($s, '-');
        $s = ltrim($s, '-+');
        [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $frac = substr(str_pad($frac, 8, '0'), 0, 8);

        $u = ((int) ($int === '' ? '0' : $int)) * self::SCALE + (int) $frac;

        return $neg ? -$u : $u;
    }

    public static function fmt(int $units): string
    {
        $neg = $units < 0;
        $units = abs($units);
        $s = intdiv($units, self::SCALE) . '.' . str_pad((string) ($units % self::SCALE), 8, '0', STR_PAD_LEFT);

        return ($neg ? '-' : '') . $s;
    }

    public static function toFloat(int $units): float
    {
        return $units / self::SCALE;
    }

    /** round(a*b / 1e8) half-up, exact integer math (a, b in 1e-8 units) */
    public static function mulUnits(int $a, int $b): int
    {
        // a*b can exceed int64 (1e13 * 1e13) -> exact decimal math through bcmath.
        $neg = ($a < 0) !== ($b < 0);
        $prod = bcmul((string) abs($a), (string) abs($b), 0);
        $half = bcadd($prod, (string) intdiv(self::SCALE, 2), 0);
        $q = (int) bcdiv($half, (string) self::SCALE, 0);

        return $neg ? -$q : $q;
    }

    public function setFree(string $asset, string|float $amount): void
    {
        $this->free[strtoupper($asset)] = self::units($amount);
    }

    public function freeOf(string $asset): int
    {
        return $this->free[strtoupper($asset)] ?? 0;
    }

    public function totalValueUsdt(string $btcPrice = '0', ?array $prices = null): float
    {
        $total = 0.0;
        foreach (array_merge($this->free, []) as $asset => $units) {
            $amount = self::toFloat($units + ($this->locked[$asset] ?? 0));
            if ($asset === 'USDT') {
                $total += $amount;
            } elseif ($asset === 'BNB') {
                $total += $amount * ($this->bnbBookPrice ?? $this->bnbPrice);
            } elseif (isset($this->lastPrice[$asset . 'USDT'])) {
                $total += $amount * $this->lastPrice[$asset . 'USDT'];
            }
        }

        return $total;
    }

    // ------------------------------------------------------------------ http faking

    public function install(): void
    {
        Http::fake(fn (Request $request) => $this->handle($request));
    }

    private function json(array $body, int $status = 200): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response($body, $status);
    }

    private function err(int $code, string $msg, int $status = 400): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['code' => $code, 'msg' => $msg], $status);
    }

    private function params(Request $request): array
    {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $data = $request->method() === 'POST' ? (array) $request->data() : [];

        return array_merge($query, $data);
    }

    public function handle(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $method = strtoupper($request->method());
        $p = $this->params($request);
        $this->calls[] = ['method' => $method, 'path' => $path, 'symbol' => $p['symbol'] ?? null, 'clientOrderId' => $p['newClientOrderId'] ?? ($p['origClientOrderId'] ?? null), 'nowMs' => $this->nowMs];

        switch (true) {
            case $path === '/api/v3/time':
                return $this->json(['serverTime' => $this->nowMs]);

            case $path === '/api/v3/ticker/price' && $method === 'GET':
                if ($this->tickerDown) {
                    return $this->json(['code' => -1001, 'msg' => 'Internal error; unable to process your request.'], 500);
                }
                $symbol = strtoupper((string) ($p['symbol'] ?? ''));
                if (! isset($this->lastPrice[$symbol])) {
                    return $this->err(-1121, 'Invalid symbol.');
                }

                return $this->json(['symbol' => $symbol, 'price' => number_format($this->lastPrice[$symbol], 8, '.', '')]);

            case $path === '/api/v3/exchangeInfo' && $method === 'GET':
                return $this->exchangeInfo((string) ($p['symbol'] ?? ''));

            case $path === '/api/v3/account' && $method === 'GET':
                if ($this->accountDown) {
                    return $this->json(['code' => -1001, 'msg' => 'Internal error; unable to process your request.'], 500);
                }

                return $this->account();

            case $path === '/api/v3/order' && $method === 'POST':
                return $this->newOrder($p);

            case $path === '/api/v3/order' && $method === 'GET':
                return $this->getOrder($p);

            case $path === '/api/v3/order' && $method === 'DELETE':
                return $this->err(-2011, 'Unknown order sent.');

            case $path === '/api/v3/myTrades' && $method === 'GET':
                return $this->myTrades($p);

            case $path === '/api/v3/openOrders':
                return $this->json([]);
        }

        $this->unhandled[] = "{$method} {$path}";

        return $this->err(-1000, "FakeBinanceSpot: unhandled endpoint {$method} {$path}", 404);
    }

    private function exchangeInfo(string $symbol)
    {
        $out = [];
        foreach ($this->symbols as $sym => $r) {
            if ($symbol !== '' && strtoupper($symbol) !== $sym) {
                continue;
            }
            $out[] = [
                'symbol' => $sym, 'status' => 'TRADING', 'baseAsset' => $r['base'], 'quoteAsset' => $r['quote'],
                'baseAssetPrecision' => 8, 'quotePrecision' => 8, 'quoteAssetPrecision' => 8,
                'filters' => [
                    ['filterType' => 'PRICE_FILTER', 'minPrice' => self::fmt($r['tick']), 'maxPrice' => '1000000.00000000', 'tickSize' => self::fmt($r['tick'])],
                    ['filterType' => 'LOT_SIZE', 'minQty' => self::fmt($r['minQty']), 'maxQty' => self::fmt($r['maxQty']), 'stepSize' => self::fmt($r['step'])],
                    ['filterType' => 'NOTIONAL', 'minNotional' => self::fmt($r['minNotional']), 'applyMinToMarket' => true, 'maxNotional' => '9000000.00000000', 'applyMaxToMarket' => false, 'avgPriceMins' => 5],
                    ['filterType' => 'MARKET_LOT_SIZE', 'minQty' => '0.00000000', 'maxQty' => '100.00000000', 'stepSize' => self::fmt($r['step'])],
                ],
            ];
        }

        return $this->json(['timezone' => 'UTC', 'serverTime' => $this->nowMs, 'symbols' => $out]);
    }

    private function account()
    {
        $assets = array_unique(array_merge(array_keys($this->free), array_keys($this->locked)));
        sort($assets);
        $balances = [];
        foreach ($assets as $a) {
            $balances[] = ['asset' => $a, 'free' => self::fmt($this->free[$a] ?? 0), 'locked' => self::fmt($this->locked[$a] ?? 0)];
        }

        return $this->json(['canTrade' => true, 'accountType' => 'SPOT', 'updateTime' => $this->nowMs, 'balances' => $balances]);
    }

    private function orderPayload(array $o, bool $withFills): array
    {
        $payload = [
            'symbol' => $o['symbol'], 'orderId' => $o['orderId'], 'orderListId' => -1, 'clientOrderId' => $o['clientOrderId'],
            'transactTime' => $o['time'], 'time' => $o['time'], 'updateTime' => $o['time'], 'workingTime' => $o['time'],
            'price' => '0.00000000', 'origQty' => self::fmt($o['origQty']), 'executedQty' => self::fmt($o['executedQty']),
            'cummulativeQuoteQty' => self::fmt($o['quoteQty']), 'status' => $o['status'], 'timeInForce' => 'GTC',
            'type' => 'MARKET', 'side' => $o['side'], 'isWorking' => true, 'selfTradePreventionMode' => 'EXPIRE_MAKER',
        ];
        if ($withFills) {
            $payload['fills'] = array_map(fn (array $t) => [
                'price' => number_format($t['price'], 8, '.', ''), 'qty' => self::fmt($t['qty']),
                'commission' => self::fmt($t['commission']), 'commissionAsset' => $t['commissionAsset'], 'tradeId' => $t['tradeId'],
            ], $o['fills']);
        }

        return $payload;
    }

    private function reject(string $kind, array $p, int $code, string $msg)
    {
        $this->rejections[] = ['kind' => $kind, 'symbol' => $p['symbol'] ?? null, 'side' => $p['side'] ?? null, 'quantity' => $p['quantity'] ?? null, 'clientOrderId' => $p['newClientOrderId'] ?? null, 'nowMs' => $this->nowMs, 'code' => $code];

        return $this->err($code, $msg);
    }

    private function newOrder(array $p)
    {
        $symbol = strtoupper((string) ($p['symbol'] ?? ''));
        $side = strtoupper((string) ($p['side'] ?? ''));
        $type = strtoupper((string) ($p['type'] ?? ''));
        $rule = $this->symbols[$symbol] ?? null;

        $fault = $this->failNextOrder;
        $this->failNextOrder = null;

        if (! $rule) {
            return $this->reject('invalid_symbol', $p, -1121, 'Invalid symbol.');
        }
        if ($type !== 'MARKET') {
            return $this->reject('unsupported_type', $p, -1116, "FakeBinanceSpot supports MARKET only, got {$type}");
        }
        if (! in_array($side, ['BUY', 'SELL'], true)) {
            return $this->reject('bad_side', $p, -1102, 'Mandatory parameter side was not sent or was malformed.');
        }

        if ($fault === 'http500_before') {
            return $this->json(['code' => -1001, 'msg' => 'Internal error; unable to process your request. Please try again.'], 500);
        }

        $qty = self::units((string) ($p['quantity'] ?? '0'));
        // LOT_SIZE
        if ($qty < $rule['minQty'] || $qty > $rule['maxQty'] || ($qty - $rule['minQty']) % $rule['step'] !== 0) {
            return $this->reject('lot_size', $p, -1013, 'Filter failure: LOT_SIZE');
        }
        $price = $this->nextOpen[$symbol] ?? $this->lastPrice[$symbol] ?? 0.0;
        if ($price <= 0) {
            return $this->reject('no_price', $p, -1013, 'Filter failure: PRICE_FILTER');
        }
        $refNotional = self::mulUnits($qty, self::units($this->lastPrice[$symbol] ?? $price));
        if ($fault === 'reject_notional' || $refNotional < $rule['minNotional']) {
            return $this->reject('notional', $p, -1013, 'Filter failure: NOTIONAL');
        }
        if ($fault === 'insufficient') {
            return $this->reject('insufficient_injected', $p, -2010, 'Account has insufficient balance for requested action.');
        }

        $clientId = (string) ($p['newClientOrderId'] ?? ('fake-' . ($this->orderSeq + 1)));
        $existingId = $this->clientIndex[$clientId] ?? null;

        // execution
        $fillQty = $qty;
        $status = 'FILLED';
        if ($fault === 'partial') {
            $fillQty = intdiv((int) floor($qty * $this->partialRatio), $rule['step']) * $rule['step'];
            if ($fillQty < $rule['minQty']) {
                $fillQty = 0;
            }
            $status = 'EXPIRED';
        }

        $priceUnits = self::units($price);
        $quoteQty = self::mulUnits($fillQty, $priceUnits);
        $base = $rule['base'];
        $quote = $rule['quote'];

        // fee
        $feeAsset = '';
        $fee = 0;
        if ($fillQty > 0) {
            if ($this->feeMode === 'bnb') {
                $feeAsset = 'BNB';
                $feeQuote = intdiv($quoteQty * 75, 100000); // 0.075%
                $bnbUnits = $this->bnbPrice > 0 ? self::mulUnits($feeQuote, intdiv(self::SCALE * self::SCALE, self::units($this->bnbPrice))) : 0;
                $fee = max(1, $bnbUnits);
            } elseif ($side === 'BUY') {
                $feeAsset = $base;
                $fee = (int) round($fillQty * 0.001); // 0.10% of base, half-up to 1e-8
            } else {
                $feeAsset = $quote;
                $fee = (int) round($quoteQty * 0.001);
            }
        }

        // balance sufficiency (free balance, incl. BNB for fee)
        $needQuote = $side === 'BUY' ? $quoteQty : 0;
        $needBase = $side === 'SELL' ? $fillQty : 0;
        if ($feeAsset === 'BNB' && $this->freeOf('BNB') < $fee) {
            // Binance falls back to the received asset when BNB is insufficient; model that.
            $feeAsset = $side === 'BUY' ? $base : $quote;
            $fee = $side === 'BUY' ? (int) round($fillQty * 0.001) : (int) round($quoteQty * 0.001);
        }
        if ($this->freeOf($quote) < $needQuote || $this->freeOf($base) < $needBase) {
            return $this->reject('insufficient_balance', $p, -2010, 'Account has insufficient balance for requested action.');
        }

        if ($existingId !== null) {
            $this->duplicateClientIdFills++;
        }

        $orderId = ++$this->orderSeq;
        $fills = [];
        if ($fillQty > 0) {
            // settle
            if ($side === 'BUY') {
                $this->free[$quote] = $this->freeOf($quote) - $quoteQty;
                $this->free[$base] = $this->freeOf($base) + $fillQty;
            } else {
                $this->free[$base] = $this->freeOf($base) - $fillQty;
                $this->free[$quote] = $this->freeOf($quote) + $quoteQty;
            }
            $this->free[$feeAsset] = $this->freeOf($feeAsset) - $fee;
            if ($this->free[$feeAsset] < 0) {
                // would be a model bug: fee larger than the asset just received
                $this->free[$feeAsset] = 0;
            }
            $trade = [
                'tradeId' => ++$this->tradeSeq, 'orderId' => $orderId, 'symbol' => $symbol, 'side' => $side, 'price' => $price,
                'qty' => $fillQty, 'quoteQty' => $quoteQty, 'commission' => $fee, 'commissionAsset' => $feeAsset,
                'time' => $this->nowMs, 'clientOrderId' => $clientId,
            ];
            $fills[] = $trade;
            $this->trades[] = $trade;
        }

        $order = [
            'orderId' => $orderId, 'symbol' => $symbol, 'side' => $side, 'clientOrderId' => $clientId, 'origQty' => $qty,
            'executedQty' => $fillQty, 'quoteQty' => $quoteQty, 'status' => $fillQty > 0 ? $status : 'EXPIRED', 'time' => $this->nowMs, 'fills' => $fills,
        ];
        $this->orders[$orderId] = $order;
        $this->clientIndex[$clientId] = $orderId;

        if ($fault === 'http500_after') {
            return $this->json(['code' => -1001, 'msg' => 'Internal error; unable to process your request. Please try again.'], 500);
        }
        if ($fault === 'timeout1007') {
            return $this->json(['code' => -1007, 'msg' => 'Timeout waiting for response from backend server. Send status unknown; execution status unknown.'], 503);
        }

        return $this->json($this->orderPayload($order, true));
    }

    private function getOrder(array $p)
    {
        $order = null;
        if (isset($p['orderId'])) {
            $order = $this->orders[(int) $p['orderId']] ?? null;
        } elseif (isset($p['origClientOrderId'])) {
            $id = $this->clientIndex[(string) $p['origClientOrderId']] ?? null;
            $order = $id !== null ? ($this->orders[$id] ?? null) : null;
        }
        if (! $order) {
            return $this->err(-2013, 'Order does not exist.');
        }

        return $this->json($this->orderPayload($order, false));
    }

    private function myTrades(array $p)
    {
        $rows = [];
        foreach ($this->trades as $t) {
            if (isset($p['orderId']) && (int) $p['orderId'] !== $t['orderId']) {
                continue;
            }
            if (isset($p['symbol']) && strtoupper((string) $p['symbol']) !== $t['symbol']) {
                continue;
            }
            $rows[] = [
                'symbol' => $t['symbol'], 'id' => $t['tradeId'], 'orderId' => $t['orderId'], 'price' => number_format($t['price'], 8, '.', ''),
                'qty' => self::fmt($t['qty']), 'quoteQty' => self::fmt($t['quoteQty']), 'commission' => self::fmt($t['commission']),
                'commissionAsset' => $t['commissionAsset'], 'time' => $t['time'], 'isBuyer' => $t['side'] === 'BUY', 'isMaker' => false, 'isBestMatch' => true,
            ];
        }

        return $this->json($rows);
    }

    // ------------------------------------------------------------------ checkpointing (resume by month)

    public function snapshot(): array
    {
        return [
            'free' => $this->free, 'locked' => $this->locked, 'orders' => $this->orders, 'clientIndex' => $this->clientIndex,
            'trades' => $this->trades, 'orderSeq' => $this->orderSeq, 'tradeSeq' => $this->tradeSeq,
            'duplicateClientIdFills' => $this->duplicateClientIdFills, 'rejections' => $this->rejections,
        ];
    }

    public function restore(array $s): void
    {
        $this->free = $s['free'];
        $this->locked = $s['locked'];
        $this->orders = array_combine(array_map('intval', array_keys($s['orders'])), array_values($s['orders']));
        $this->clientIndex = $s['clientIndex'];
        $this->trades = $s['trades'];
        $this->orderSeq = $s['orderSeq'];
        $this->tradeSeq = $s['tradeSeq'];
        $this->duplicateClientIdFills = $s['duplicateClientIdFills'];
        $this->rejections = $s['rejections'];
    }
}
