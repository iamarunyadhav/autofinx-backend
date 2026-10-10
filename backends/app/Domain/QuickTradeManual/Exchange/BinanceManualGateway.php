<?php

namespace App\Domain\QuickTradeManual\Exchange;

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Exchange\BinanceSymbolNormalizer;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * ManualExchangeGateway over BinanceFuturesClient. Endpoints used for orders/positions/fills
 * are the ones already used by the existing Quick Trade / Future Trade code; the conditional
 * (protective) endpoints come from BinanceAlgoApi (routes/fields verified against Binance docs 2026-10-10, still not run on a live account).
 */
class BinanceManualGateway implements ManualExchangeGateway
{
    public function __construct(
        private readonly BinanceFuturesClient $client,
        private readonly string $environment,
    ) {
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function isHedgeMode(): bool
    {
        $row = $this->read(fn () => $this->client->fetchPositionMode());
        $value = $row['dualSidePosition'] ?? null;
        if ($value === null) {
            throw ManualExchangeException::unknownOutcome('Could not determine position mode (dualSidePosition missing).');
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function symbolRules(string $symbol): array
    {
        $symbol = BinanceSymbolNormalizer::normalize($symbol);
        $info = $this->read(fn () => $this->client->fetchExchangeInfo($symbol));
        $row = collect((array) ($info['symbols'] ?? []))
            ->first(fn ($r) => is_array($r) && strtoupper((string) ($r['symbol'] ?? '')) === $symbol);
        if (! is_array($row)) {
            throw ManualExchangeException::unknownOutcome("Symbol {$symbol} not found in exchangeInfo.");
        }

        $filters = collect((array) ($row['filters'] ?? []))
            ->mapWithKeys(fn ($f) => [strtoupper((string) ($f['filterType'] ?? '')) => (array) $f]);
        $price = $filters->get('PRICE_FILTER', []);
        $lot = $filters->get('MARKET_LOT_SIZE') ?: $filters->get('LOT_SIZE') ?: [];
        $notional = $filters->get('MIN_NOTIONAL') ?: $filters->get('NOTIONAL') ?: [];

        $tick = (float) ($price['tickSize'] ?? 0);
        $step = (float) ($lot['stepSize'] ?? 0);
        if ($tick <= 0 || $step <= 0) {
            // Never guess a tick/step: an unverifiable rule means no order.
            throw ManualExchangeException::unknownOutcome("tickSize/stepSize unavailable for {$symbol}.");
        }

        return [
            'tick_size' => $tick,
            'step_size' => $step,
            'min_qty' => (float) ($lot['minQty'] ?? $step),
            'min_notional' => (float) ($notional['notional'] ?? $notional['minNotional'] ?? 0),
        ];
    }

    public function markPrice(string $symbol): array
    {
        $row = $this->read(fn () => $this->client->fetchMarkPrice($symbol));
        $mark = (float) ($row['markPrice'] ?? 0);
        if ($mark <= 0) {
            throw ManualExchangeException::unknownOutcome('Mark price unavailable.');
        }

        return [
            'mark_price' => $mark,
            'time_ms' => (int) ($row['time'] ?? 0),
            'funding_rate' => isset($row['lastFundingRate']) && is_numeric($row['lastFundingRate']) ? (float) $row['lastFundingRate'] : null,
            'next_funding_ms' => isset($row['nextFundingTime']) ? (int) $row['nextFundingTime'] : null,
        ];
    }

    public function availableBalance(): float
    {
        $rows = $this->json($this->call('GET', '/fapi/v2/balance'), 'balance');
        foreach ((array) $rows as $row) {
            if (is_array($row) && strtoupper((string) ($row['asset'] ?? '')) === 'USDT') {
                return (float) ($row['availableBalance'] ?? $row['withdrawAvailable'] ?? 0);
            }
        }

        return 0.0;
    }

    public function leverageBrackets(string $symbol): ?array
    {
        try {
            return $this->client->leverageBrackets($symbol);
        } catch (Throwable) {
            return null;
        }
    }

    public function position(string $symbol, ?string $positionSide): array
    {
        $rows = (array) $this->read(fn () => $this->client->fetchPositionRisk($symbol));
        $want = $positionSide !== null ? strtoupper($positionSide) : 'BOTH';
        $symbol = BinanceSymbolNormalizer::normalize($symbol);
        $match = null;
        foreach ($rows as $row) {
            if (! is_array($row) || strtoupper((string) ($row['symbol'] ?? '')) !== $symbol) {
                continue;
            }
            $rowSide = strtoupper((string) ($row['positionSide'] ?? 'BOTH'));
            if ($rowSide === $want) {
                $match = $row;
                break;
            }
        }
        if ($match === null) {
            // Binance lists the symbol even when flat; a missing row is an unreadable answer, not "flat".
            throw ManualExchangeException::unknownOutcome("positionRisk returned no {$want} row for {$symbol}.");
        }

        $liq = isset($match['liquidationPrice']) && is_numeric($match['liquidationPrice']) ? (float) $match['liquidationPrice'] : null;

        return [
            'amount' => (float) ($match['positionAmt'] ?? 0),
            'entry_price' => (float) ($match['entryPrice'] ?? 0),
            'mark_price' => (float) ($match['markPrice'] ?? 0),
            'liquidation_price' => ($liq !== null && $liq > 0) ? $liq : null,
            'leverage' => isset($match['leverage']) && is_numeric($match['leverage']) ? (float) $match['leverage'] : null,
            'margin_type' => isset($match['marginType']) ? strtolower((string) $match['marginType']) : null,
            'unrealized_pnl' => (float) ($match['unRealizedProfit'] ?? $match['unrealizedProfit'] ?? 0),
        ];
    }

    public function setLeverage(string $symbol, int $leverage): void
    {
        try {
            $this->client->setLeverage($symbol, $leverage);
        } catch (Throwable $e) {
            $m = strtolower($e->getMessage());
            if (str_contains($m, '-4046') || str_contains($m, 'no need to change')) {
                return;
            }
            throw $this->wrap($e);
        }
    }

    public function placeEntry(array $p): array
    {
        return $this->placeMarket($p, false);
    }

    public function placeClose(array $p): array
    {
        return $this->placeMarket($p, true);
    }

    public function queryOrder(string $symbol, ?string $orderId, ?string $clientOrderId): ?array
    {
        $params = ['symbol' => BinanceSymbolNormalizer::normalize($symbol)];
        if ($orderId !== null && $orderId !== '') {
            $params['orderId'] = $orderId;
        }
        if ($clientOrderId !== null && $clientOrderId !== '') {
            $params['origClientOrderId'] = $clientOrderId;
        }
        $response = $this->call('GET', '/fapi/v1/order', $params);
        if ($response->successful()) {
            return $this->parseOrder((array) $response->json());
        }
        if ((int) $response->json('code') === -2013) {   // Order does not exist
            return null;
        }
        throw $this->failure($response, 'order query');
    }

    public function placeProtective(array $p): array
    {
        $response = $this->call('POST', BinanceAlgoApi::PLACE_PATH, BinanceAlgoApi::placeParams($p), true);
        if (! $response->successful()) {
            throw $this->failure($response, 'protective order placement');
        }
        $parsed = BinanceAlgoApi::parse((array) $response->json());
        if ($parsed['algo_id'] === '') {
            // 2xx without an algoId is NOT an acknowledgement.
            throw ManualExchangeException::unknownOutcome('Protective order response had no algoId.');
        }

        return [
            'algo_id' => $parsed['algo_id'],
            'client_algo_id' => $parsed['client_algo_id'] !== '' ? $parsed['client_algo_id'] : $p['client_algo_id'],
            'status' => $parsed['status'],
        ];
    }

    public function queryProtective(string $symbol, ?string $algoId, ?string $clientAlgoId): ?array
    {
        $params = [];
        if ($algoId !== null && $algoId !== '') {
            $params['algoId'] = $algoId;
        }
        if ($clientAlgoId !== null && $clientAlgoId !== '') {
            $params[BinanceAlgoApi::CLIENT_ID_PARAM] = $clientAlgoId;
        }
        if ($params === []) {
            return null;
        }
        $response = $this->call('GET', BinanceAlgoApi::QUERY_PATH, $params);
        if ($response->successful()) {
            $json = (array) $response->json();
            // Some answers wrap the order in a list.
            if (array_is_list($json)) {
                $json = (array) ($json[0] ?? []);
            }
            $parsed = BinanceAlgoApi::parse($json);

            return $parsed['algo_id'] === '' ? null : $parsed;
        }
        if ($this->looksLikeNotFound($response)) {
            return null;
        }
        throw $this->failure($response, 'protective order query');
    }

    public function cancelProtective(string $symbol, ?string $algoId, ?string $clientAlgoId): void
    {
        $params = [];
        if ($algoId !== null && $algoId !== '') {
            $params['algoId'] = $algoId;
        }
        if ($clientAlgoId !== null && $clientAlgoId !== '') {
            $params[BinanceAlgoApi::CLIENT_ID_PARAM] = $clientAlgoId;
        }
        $response = $this->call('DELETE', BinanceAlgoApi::CANCEL_PATH, $params, true);
        if ($response->successful() || $this->looksLikeNotFound($response)) {
            return;   // already gone is success for an idempotent cancel
        }
        throw $this->failure($response, 'protective order cancel');
    }

    public function orderFills(string $symbol, string $orderId): ?array
    {
        if (trim($orderId) === '' || trim($orderId) === '0') {
            return null;
        }
        try {
            $response = $this->call('GET', '/fapi/v1/userTrades', [
                'symbol' => BinanceSymbolNormalizer::normalize($symbol),
                'orderId' => $orderId,
            ]);
        } catch (Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $rows = (array) $response->json();
        if ($rows === []) {
            return null;
        }
        $commission = $pnl = $qty = $quote = 0.0;
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['commission'], $row['commissionAsset'], $row['qty'], $row['price'])
                || ! is_numeric($row['commission']) || strtoupper((string) $row['commissionAsset']) !== 'USDT') {
                return null;   // mixed/unknown fee asset or malformed row: do not guess
            }
            $commission += (float) $row['commission'];
            $pnl += (float) ($row['realizedPnl'] ?? 0);
            $qty += (float) $row['qty'];
            $quote += (float) $row['qty'] * (float) $row['price'];
        }
        if ($qty <= 0) {
            return null;
        }

        return [
            'commission' => round($commission, 8),
            'realized_pnl' => round($pnl, 8),
            'quantity' => round($qty, 10),
            'avg_price' => $quote / $qty,
            'fills' => count($rows),
        ];
    }

    public function fundingIncome(string $symbol, int $fromMs, int $toMs): ?float
    {
        try {
            $response = $this->call('GET', '/fapi/v1/income', [
                'symbol' => BinanceSymbolNormalizer::normalize($symbol),
                'incomeType' => 'FUNDING_FEE',
                'startTime' => $fromMs,
                'endTime' => $toMs,
                'limit' => 1000,
            ]);
        } catch (Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $rows = (array) $response->json();
        if (count($rows) >= 1000) {
            return null;   // possibly truncated
        }
        $sum = 0.0;
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['income']) || ! is_numeric($row['income'])
                || strtoupper((string) ($row['asset'] ?? '')) !== 'USDT') {
                return null;
            }
            $sum += (float) $row['income'];
        }

        return round($sum, 8);
    }

    public function liquidationSince(string $symbol, int $sinceMs): ?bool
    {
        try {
            $response = $this->call('GET', BinanceAlgoApi::FORCE_ORDERS_PATH, [
                'symbol' => BinanceSymbolNormalizer::normalize($symbol),
                'autoCloseType' => 'LIQUIDATION',
                'startTime' => $sinceMs,
            ]);
        } catch (Throwable) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $rows = $response->json();

        return is_array($rows) ? $rows !== [] : null;
    }

    public function tradesSince(string $symbol, int $sinceMs): ?array
    {
        try {
            $response = $this->call('GET', '/fapi/v1/userTrades', [
                'symbol' => BinanceSymbolNormalizer::normalize($symbol),
                'startTime' => $sinceMs,
                'limit' => 1000,
            ]);
        } catch (Throwable) {
            return null;
        }
        if (! $response->successful() || ! is_array($response->json())) {
            return null;
        }
        $out = [];
        foreach ((array) $response->json() as $row) {
            if (! is_array($row) || ! isset($row['price'], $row['qty'])) {
                return null;
            }
            if (strtoupper((string) ($row['commissionAsset'] ?? 'USDT')) !== 'USDT') {
                return null;
            }
            $out[] = [
                'order_id' => (string) ($row['orderId'] ?? ''),
                'side' => strtoupper((string) ($row['side'] ?? '')),
                'price' => (float) $row['price'],
                'quantity' => (float) $row['qty'],
                'commission' => (float) ($row['commission'] ?? 0),
                'realized_pnl' => (float) ($row['realizedPnl'] ?? 0),
                'time_ms' => (int) ($row['time'] ?? 0),
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- internals

    /** @param array{symbol: string, side: string, quantity: string, position_side: ?string, client_order_id: string} $p */
    private function placeMarket(array $p, bool $close): array
    {
        $params = [
            'symbol' => BinanceSymbolNormalizer::normalize($p['symbol']),
            'side' => strtoupper($p['side']),
            'type' => 'MARKET',
            'quantity' => $p['quantity'],
            'newOrderRespType' => 'RESULT',
            'newClientOrderId' => $p['client_order_id'],
        ];
        if (! empty($p['position_side'])) {
            $params['positionSide'] = strtoupper($p['position_side']);   // hedge mode (reduceOnly is not allowed there)
        } elseif ($close) {
            $params['reduceOnly'] = 'true';                              // one-way: can never reverse the position
        }

        $response = $this->call('POST', '/fapi/v1/order', $params, true);
        if (! $response->successful()) {
            throw $this->failure($response, $close ? 'close order' : 'entry order');
        }

        return $this->parseOrder((array) $response->json());
    }

    /** @return array{order_id: string, client_order_id: string, status: string, executed_qty: float, avg_price: float} */
    private function parseOrder(array $json): array
    {
        $avg = (float) ($json['avgPrice'] ?? 0);
        if ($avg <= 0 && (float) ($json['executedQty'] ?? 0) > 0 && (float) ($json['cumQuote'] ?? 0) > 0) {
            $avg = (float) $json['cumQuote'] / (float) $json['executedQty'];
        }

        return [
            'order_id' => isset($json['orderId']) ? (string) $json['orderId'] : '',
            'client_order_id' => (string) ($json['clientOrderId'] ?? ''),
            'status' => strtoupper((string) ($json['status'] ?? '')),
            'executed_qty' => (float) ($json['executedQty'] ?? 0),
            'avg_price' => $avg,
        ];
    }

    /**
     * @param  bool  $mutating  true for calls that change exchange state (a transport failure is then "outcome unknown")
     */
    private function call(string $method, string $path, array $params = [], bool $mutating = false): Response
    {
        try {
            return $this->client->signedApiCall($method, $path, $params);
        } catch (ManualExchangeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->wrap($e, $mutating);
        }
    }

    private function wrap(Throwable $e, bool $mutating = true): ManualExchangeException
    {
        $m = $e->getMessage();
        // Raised BEFORE any request leaves this process: definitely nothing happened.
        if (str_starts_with($m, 'binance_rest_circuit_open') || str_starts_with($m, 'binance_key_dead')
            || str_contains($m, 'binance_rest_circuit_open') || str_contains($m, 'credentials are required')) {
            return ManualExchangeException::rejected($this->redact($m));
        }

        return $mutating
            ? ManualExchangeException::unknownOutcome($this->redact($m))
            : ManualExchangeException::rejected($this->redact($m));
    }

    private function failure(Response $response, string $what): ManualExchangeException
    {
        $code = $response->json('code');
        $code = is_numeric($code) ? (int) $code : null;
        $msg = sprintf('Binance %s failed (HTTP %d, code %s): %s', $what, $response->status(), $code ?? 'n/a', mb_substr((string) $response->json('msg'), 0, 200));

        // 5xx, -1006 unexpected response, -1007 timeout (status unknown), -1001 internal: outcome unknown.
        if ($response->status() >= 500 || in_array($code, [-1001, -1006, -1007], true)) {
            return ManualExchangeException::unknownOutcome($msg, $code);
        }

        return ManualExchangeException::rejected($msg, $code);
    }

    private function looksLikeNotFound(Response $response): bool
    {
        $code = (int) $response->json('code');
        $msg = strtolower((string) $response->json('msg'));

        return in_array($code, [-2013, -2011], true) || str_contains($msg, 'does not exist') || str_contains($msg, 'not found') || str_contains($msg, 'unknown order');
    }

    /** Run a client read that throws RuntimeException on non-2xx. */
    private function read(callable $fn): array
    {
        try {
            return (array) $fn();
        } catch (Throwable $e) {
            throw ManualExchangeException::unknownOutcome($this->redact($e->getMessage()));
        }
    }

    private function json(Response $response, string $what): array
    {
        if (! $response->successful()) {
            throw $this->failure($response, $what);
        }

        return (array) $response->json();
    }

    private function redact(string $message): string
    {
        $message = (string) preg_replace('/(signature|X-MBX-APIKEY|api_key|api_secret|apiKey|secret)([=:]\s*)[^&\s"\']+/i', '$1$2[redacted]', $message);

        return mb_substr($message, 0, 300);
    }
}
