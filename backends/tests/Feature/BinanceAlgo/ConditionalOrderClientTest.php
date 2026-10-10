<?php

use App\Domain\Exchange\BinanceAlgoOrderApi;
use App\Domain\Exchange\BinanceConditionalOrderException;
use App\Domain\Exchange\BinanceFuturesClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Binance conditional (stop/take-profit) orders: Algo Order endpoint vs legacy /fapi/v1/order.
 * Everything is Http::fake: these prove what we SEND and how we react, not what Binance accepts
 * (see docs/binance-algo-migration/CHECKLIST.md for the owner-run live verification).
 */

function algoClient(): BinanceFuturesClient
{
    return new BinanceFuturesClient('k', 's', 'https://testnet.binancefuture.com', 'algo_test');
}

function algoAck(array $over = []): array
{
    return array_merge([
        'algoId' => 2000000123, 'clientAlgoId' => 'ftx-sl-abc', 'algoType' => 'CONDITIONAL', 'orderType' => 'STOP_MARKET',
        'symbol' => 'BTCUSDT', 'side' => 'SELL', 'positionSide' => 'BOTH', 'algoStatus' => 'NEW',
        'triggerPrice' => '79200.00000000', 'workingType' => 'MARK_PRICE', 'closePosition' => true, 'reduceOnly' => false,
    ], $over);
}

function stopPost(string $coid = 'ftx-sl-abc', string $type = 'STOP_MARKET'): array
{
    return ['BTCUSDT', 'SELL', $type, 79200.0, $coid];
}

/** Route Http::fake by "METHOD /path" and record every request (query + form body). */
function fakeBinance(array $routes, array &$log): void
{
    Http::swap(new \Illuminate\Http\Client\Factory);   // a second fake() in one test would be shadowed by the first
    Http::fake(function ($request) use ($routes, &$log) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $key = $request->method().' '.$path;
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        parse_str((string) $request->body(), $form);
        $log[] = ['key' => $key, 'query' => $query, 'form' => $form, 'url' => $request->url()];
        $handler = $routes[$key] ?? null;
        if ($handler === null) {
            return Http::response(['code' => -9999, 'msg' => 'unexpected route '.$key], 500);
        }

        return $handler;
    });
}

function hits(array $log, string $key): int
{
    return count(array_filter($log, fn ($r) => $r['key'] === $key));
}

beforeEach(function () {
    Cache::flush();
    config(['binance.conditional_order_mode' => 'auto']);
});

test('algo place: POST /fapi/v1/algoOrder with triggerPrice, closePosition and no quantity; ack mapped to the legacy keys', function () {
    config(['binance.conditional_order_mode' => 'algo']);
    $log = [];
    fakeBinance(['POST /fapi/v1/algoOrder' => Http::response(algoAck(), 200)], $log);

    $res = algoClient()->placeReduceOnlyStopOrder(...stopPost());

    expect($res['orderId'])->toBe('2000000123')
        ->and($res['clientOrderId'])->toBe('ftx-sl-abc')
        ->and($res['algo'])->toBeTrue()->and($res['endpoint'])->toBe('algo')
        ->and($res['status'])->toBe('NEW')->and($res['reduceOnly'])->toBeTrue();
    $form = $log[0]['form'];
    expect($form['algoType'])->toBe('CONDITIONAL')
        ->and($form['triggerPrice'])->toBe('79200.00000000')->and($form)->not->toHaveKey('stopPrice')
        ->and($form['closePosition'])->toBe('true')->and($form)->not->toHaveKey('quantity')->and($form)->not->toHaveKey('reduceOnly')
        ->and($form['clientAlgoId'])->toBe('ftx-sl-abc')->and($form)->not->toHaveKey('newClientOrderId')
        ->and($form['workingType'])->toBe('MARK_PRICE')->and($form)->not->toHaveKey('positionSide')
        ->and($form['signature'])->not->toBeEmpty();
    expect(hits($log, 'POST /fapi/v1/order'))->toBe(0);
});

test('hedge mode: positionSide is sent on the algo order', function () {
    config(['binance.conditional_order_mode' => 'algo']);
    $log = [];
    fakeBinance(['POST /fapi/v1/algoOrder' => Http::response(algoAck(['positionSide' => 'LONG']), 200)], $log);

    algoClient()->placeReduceOnlyStopOrder('BTCUSDT', 'SELL', 'STOP_MARKET', 79200.0, 'ftx-sl-abc', 'MARK_PRICE', 'LONG');

    expect($log[0]['form']['positionSide'])->toBe('LONG');
});

test('clientAlgoId: 36 chars accepted, 37 chars or illegal characters rejected before any HTTP call', function () {
    config(['binance.conditional_order_mode' => 'algo']);
    $log = [];
    fakeBinance(['POST /fapi/v1/algoOrder' => Http::response(algoAck(['clientAlgoId' => str_repeat('a', 36)]), 200)], $log);

    algoClient()->placeReduceOnlyStopOrder('BTCUSDT', 'SELL', 'STOP_MARKET', 79200.0, str_repeat('a', 36));
    expect($log[0]['form']['clientAlgoId'])->toHaveLength(36);

    foreach ([str_repeat('a', 37), 'bad id!', ''] as $bad) {
        $log = [];
        expect(fn () => algoClient()->placeReduceOnlyStopOrder('BTCUSDT', 'SELL', 'STOP_MARKET', 79200.0, $bad))
            ->toThrow(BinanceConditionalOrderException::class);
        expect($log)->toBe([]);
    }
    expect(BinanceAlgoOrderApi::isValidClientAlgoId('rbstop-abc.def:1/2_3-4'))->toBeTrue();
});

test('legacy -4120 switches to algo and the learned mode is cached (no legacy hammering)', function () {
    Cache::put('autofinx:binance:conditional_mode:'.md5('https://testnet.binancefuture.com'), 'legacy', 600);
    $log = [];
    fakeBinance([
        'POST /fapi/v1/order' => Http::response(['code' => -4120, 'msg' => 'Order type not supported for this endpoint. Please use the Algo Order API endpoints instead.'], 400),
        'POST /fapi/v1/algoOrder' => Http::response(algoAck(), 200),
    ], $log);

    $first = algoClient()->placeReduceOnlyStopOrder(...stopPost());
    expect($first['algo'])->toBeTrue();
    expect(hits($log, 'POST /fapi/v1/order'))->toBe(1)->and(hits($log, 'POST /fapi/v1/algoOrder'))->toBe(1);

    algoClient()->placeReduceOnlyStopOrder('BTCUSDT', 'SELL', 'TAKE_PROFIT_MARKET', 81000.0, 'ftx-tp-abc');
    expect(hits($log, 'POST /fapi/v1/order'))->toBe(1)->and(hits($log, 'POST /fapi/v1/algoOrder'))->toBe(2);
});

test('algo DEFINITE unsupported rejection falls back to legacy once, and legacy is remembered', function () {
    $log = [];
    fakeBinance([
        'POST /fapi/v1/algoOrder' => Http::response(['code' => -5000, 'msg' => 'Path /fapi/v1/algoOrder, Method POST is invalid'], 404),
        'POST /fapi/v1/order' => Http::response(['orderId' => 42, 'clientOrderId' => 'ftx-sl-abc', 'status' => 'NEW'], 200),
    ], $log);

    $res = algoClient()->placeReduceOnlyStopOrder(...stopPost());
    expect($res['orderId'])->toBe(42)->and($res['endpoint'])->toBe('legacy')->and($res['algo'])->toBeFalse();
    expect($log[1]['form']['stopPrice'])->toBe('79200.00000000')->and($log[1]['form']['newClientOrderId'])->toBe('ftx-sl-abc');

    // Next placement goes legacy first: the unsupported algo endpoint is not hit again.
    algoClient()->placeReduceOnlyStopOrder('BTCUSDT', 'SELL', 'TAKE_PROFIT_MARKET', 81000.0, 'ftx-tp-abc');
    expect(hits($log, 'POST /fapi/v1/algoOrder'))->toBe(1)->and(hits($log, 'POST /fapi/v1/order'))->toBe(2);
});

test('an algo rejection that is NOT "unsupported" (bad precision, duplicate id, would trigger) never reaches the legacy endpoint', function () {
    $log = [];
    fakeBinance([
        'POST /fapi/v1/algoOrder' => Http::response(['code' => -2021, 'msg' => 'Order would immediately trigger.'], 400),
        'GET /fapi/v1/openAlgoOrders' => Http::response([], 200),
        'GET /fapi/v1/algoOrder' => Http::response(['code' => -2013, 'msg' => 'Order does not exist.'], 400),
        'POST /fapi/v1/order' => Http::response(['orderId' => 1], 200),
    ], $log);
    Log::spy();

    try {
        algoClient()->placeReduceOnlyStopOrder(...stopPost());
        $this->fail('expected exception');
    } catch (BinanceConditionalOrderException $e) {
        expect($e->binanceCode)->toBe(-2021)->and($e->outcome)->toBe('rejected')->and($e->getMessage())->toContain('-2021');
    }
    expect(hits($log, 'POST /fapi/v1/order'))->toBe(0);
    Log::shouldHaveReceived('critical')->withArgs(fn ($m, $ctx = []) => str_contains($m, 'BINANCE_CONDITIONAL_ORDER_FAILED') && ($ctx['binance_code'] ?? null) === -2021)->once();
});

test('mode=algo never calls the legacy endpoint, even on an unsupported rejection', function () {
    config(['binance.conditional_order_mode' => 'algo']);
    $log = [];
    fakeBinance([
        'POST /fapi/v1/algoOrder' => Http::response(['code' => -5000, 'msg' => 'Path invalid'], 404),
        'POST /fapi/v1/order' => Http::response(['orderId' => 1], 200),
    ], $log);

    expect(fn () => algoClient()->placeReduceOnlyStopOrder(...stopPost()))->toThrow(BinanceConditionalOrderException::class);
    expect(hits($log, 'POST /fapi/v1/order'))->toBe(0);
});

test('mode=legacy keeps the old behaviour: only POST /fapi/v1/order with stopPrice/newClientOrderId, never algo', function () {
    config(['binance.conditional_order_mode' => 'legacy']);
    $log = [];
    fakeBinance([
        'POST /fapi/v1/order' => Http::response(['orderId' => 7, 'clientOrderId' => 'ftx-sl-abc', 'status' => 'NEW'], 200),
        'POST /fapi/v1/algoOrder' => Http::response(algoAck(), 200),
    ], $log);

    $res = algoClient()->placeReduceOnlyStopOrder(...stopPost());

    expect($res['orderId'])->toBe(7);
    expect(array_diff_key($log[0]['form'], ['timestamp' => 1, 'recvWindow' => 1, 'signature' => 1]))->toBe([
        'closePosition' => 'true', 'newClientOrderId' => 'ftx-sl-abc', 'side' => 'SELL', 'stopPrice' => '79200.00000000',
        'symbol' => 'BTCUSDT', 'type' => 'STOP_MARKET', 'workingType' => 'MARK_PRICE',
    ]);
    expect(hits($log, 'POST /fapi/v1/algoOrder'))->toBe(0);

    // legacy failure still throws (message format unchanged)
    $log = [];
    fakeBinance(['POST /fapi/v1/order' => Http::response(['code' => -4120, 'msg' => 'moved'], 400)], $log);
    expect(fn () => algoClient()->placeReduceOnlyStopOrder(...stopPost()))->toThrow(RuntimeException::class, 'Binance futures signed request failed.');
    expect(hits($log, 'POST /fapi/v1/algoOrder'))->toBe(0);
});

test('UNKNOWN outcome (5xx): resolved by clientAlgoId lookup, never by placing a second order or falling back to legacy', function () {
    $log = [];
    fakeBinance([
        'POST /fapi/v1/algoOrder' => Http::response(['code' => -1007, 'msg' => 'Timeout waiting for response from backend server. Send status unknown; execution status unknown.'], 503),
        'GET /fapi/v1/openAlgoOrders' => Http::response([algoAck()], 200),
        'POST /fapi/v1/order' => Http::response(['orderId' => 1], 200),
    ], $log);

    $res = algoClient()->placeReduceOnlyStopOrder(...stopPost());

    expect($res['recovered'])->toBeTrue()->and($res['orderId'])->toBe('2000000123');
    expect(hits($log, 'POST /fapi/v1/order'))->toBe(0);
    foreach (array_filter($log, fn ($r) => $r['key'] === 'POST /fapi/v1/algoOrder') as $post) {
        expect($post['form']['clientAlgoId'])->toBe('ftx-sl-abc');   // any transport retry reuses the same id
    }
    expect(hits($log, 'GET /fapi/v1/openAlgoOrders'))->toBeGreaterThanOrEqual(1);
});

test('UNKNOWN outcome and nothing found: unknown-outcome exception, no legacy call, no extra order', function () {
    $log = [];
    fakeBinance([
        'POST /fapi/v1/algoOrder' => Http::response(['code' => -1007, 'msg' => 'unknown'], 503),
        'GET /fapi/v1/openAlgoOrders' => Http::response([], 200),
        'GET /fapi/v1/algoOrder' => Http::response(['code' => -2013, 'msg' => 'Order does not exist.'], 400),
        'POST /fapi/v1/order' => Http::response(['orderId' => 1], 200),
    ], $log);

    try {
        algoClient()->placeReduceOnlyStopOrder(...stopPost());
        $this->fail('expected exception');
    } catch (BinanceConditionalOrderException $e) {
        expect($e->outcome)->toBe('unknown')->and($e->getMessage())->toContain('UNKNOWN')->toContain('not re-sent');
    }
    expect(hits($log, 'POST /fapi/v1/order'))->toBe(0);
    foreach (array_filter($log, fn ($r) => $r['key'] === 'POST /fapi/v1/algoOrder') as $post) {
        expect($post['form']['clientAlgoId'])->toBe('ftx-sl-abc');
    }
});

test('a lookup hit with a DIFFERENT type or trigger price is not mistaken for our order (SL/TP id collision, stale replace)', function () {
    $log = [];
    fakeBinance([
        'POST /fapi/v1/algoOrder' => Http::response(['code' => -4116, 'msg' => 'ClientOrderId is duplicated.'], 400),
        'GET /fapi/v1/openAlgoOrders' => Http::response([algoAck(['orderType' => 'STOP_MARKET', 'triggerPrice' => '70000.0'])], 200),
        'GET /fapi/v1/algoOrder' => Http::response(algoAck(['orderType' => 'STOP_MARKET']), 200),
    ], $log);

    // asking for a TAKE_PROFIT_MARKET with the id an open STOP_MARKET already owns
    expect(fn () => algoClient()->placeReduceOnlyStopOrder('BTCUSDT', 'SELL', 'TAKE_PROFIT_MARKET', 81000.0, 'ftx-sl-abc'))
        ->toThrow(BinanceConditionalOrderException::class, '-4116');
    // same type but different trigger price
    expect(fn () => algoClient()->placeReduceOnlyStopOrder(...stopPost()))->toThrow(BinanceConditionalOrderException::class, '-4116');
});

test('cancel: by algoId and by clientAlgoId, parameters in the QUERY STRING, signed', function () {
    config(['binance.conditional_order_mode' => 'algo']);
    $log = [];
    fakeBinance(['DELETE /fapi/v1/algoOrder' => Http::response(['algoId' => 55, 'clientAlgoId' => 'ftx-sl-abc', 'code' => '200', 'msg' => 'success'], 200)], $log);

    $a = algoClient()->cancelOrder('BTCUSDT', '55', null, true);
    $b = algoClient()->cancelOrder('BTCUSDT', null, 'ftx-sl-abc');

    expect($a['algo'])->toBeTrue()->and($log[0]['query']['algoId'])->toBe('55')->and($log[0]['query'])->toHaveKey('signature')->and($log[0]['query'])->toHaveKey('timestamp')
        ->and($log[1]['query']['clientAlgoId'])->toBe('ftx-sl-abc')->and($log[1]['query'])->not->toHaveKey('algoId');
    expect($b['endpoint'])->toBe('algo');
    expect($log[0]['form'])->toBe([]);   // nothing in a body
});

test('cancel auto, client id only: algo first, legacy only if algo failed; both failing raises', function () {
    $log = [];
    fakeBinance([
        'DELETE /fapi/v1/algoOrder' => Http::response(['code' => -2011, 'msg' => 'Unknown order sent.'], 400),
        'DELETE /fapi/v1/order' => Http::response(['status' => 'CANCELED', 'clientOrderId' => 'ftx-sl-abc'], 200),
    ], $log);
    $res = algoClient()->cancelOrder('BTCUSDT', null, 'ftx-sl-abc');
    expect($res['endpoint'])->toBe('legacy');
    expect($log[0]['key'])->toBe('DELETE /fapi/v1/algoOrder')->and($log[1]['key'])->toBe('DELETE /fapi/v1/order');
    // legacy cancel also carries its params in the query string now
    expect($log[1]['query']['symbol'])->toBe('BTCUSDT')->and($log[1]['query']['origClientOrderId'])->toBe('ftx-sl-abc')->and($log[1]['query'])->toHaveKey('signature');

    $log = [];
    fakeBinance([
        'DELETE /fapi/v1/algoOrder' => Http::response(['code' => -2011, 'msg' => 'Unknown order sent.'], 400),
        'DELETE /fapi/v1/order' => Http::response(['code' => -2011, 'msg' => 'Unknown order sent.'], 400),
    ], $log);
    expect(fn () => algoClient()->cancelOrder('BTCUSDT', null, 'ftx-sl-abc'))->toThrow(RuntimeException::class, 'both endpoints');
});

test('cancel with an explicit legacy marker or mode=legacy never touches the algo endpoint', function () {
    $log = [];
    fakeBinance(['DELETE /fapi/v1/order' => Http::response(['status' => 'CANCELED'], 200)], $log);
    algoClient()->cancelOrder('BTCUSDT', '777', 'ft-close-stale', false);
    config(['binance.conditional_order_mode' => 'legacy']);
    algoClient()->cancelOrder('BTCUSDT', null, 'ftx-sl-abc');
    expect(array_column($log, 'key'))->toBe(['DELETE /fapi/v1/order', 'DELETE /fapi/v1/order']);
    expect($log[0]['query']['orderId'])->toBe('777');
});

test('open algo orders are listed, normalised to the legacy open-order keys and marked algo (the existence check the bots use)', function () {
    $log = [];
    fakeBinance(['GET /fapi/v1/openAlgoOrders' => Http::response([algoAck(), algoAck(['algoId' => 9, 'clientAlgoId' => 'tp1', 'orderType' => 'TAKE_PROFIT_MARKET'])], 200)], $log);

    $rows = algoClient()->fetchOpenAlgoOrders('btcusdt');

    expect($log[0]['query']['symbol'])->toBe('BTCUSDT');
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['orderId'])->toBe('2000000123')->and($rows[0]['clientOrderId'])->toBe('ftx-sl-abc')
        ->and($rows[0]['reduceOnly'])->toBeTrue()->and($rows[0]['algo'])->toBeTrue()
        ->and($rows[0]['stopPrice'])->toBe('79200.00000000')->and($rows[1]['type'])->toBe('TAKE_PROFIT_MARKET');
});

test('open algo orders: legacy mode makes no HTTP call; a failed/odd answer raises instead of reporting "no stops"', function () {
    $log = [];
    fakeBinance([], $log);
    config(['binance.conditional_order_mode' => 'legacy']);
    expect(algoClient()->fetchOpenAlgoOrders('BTCUSDT'))->toBe([]);
    expect($log)->toBe([]);

    config(['binance.conditional_order_mode' => 'algo']);
    fakeBinance(['GET /fapi/v1/openAlgoOrders' => Http::response(['code' => -1000, 'msg' => 'boom'], 400)], $log);
    expect(fn () => algoClient()->fetchOpenAlgoOrders('BTCUSDT'))->toThrow(RuntimeException::class);
    fakeBinance(['GET /fapi/v1/openAlgoOrders' => Http::response(['orders' => []], 200)], $log);
    expect(fn () => algoClient()->fetchOpenAlgoOrders('BTCUSDT'))->toThrow(RuntimeException::class, 'unexpected response shape');
});

test('cancelOpenOrders also clears algo orders (legacy allOpenOrders does not), params in the query string', function () {
    $log = [];
    fakeBinance([
        'DELETE /fapi/v1/allOpenOrders' => Http::response(['code' => 200, 'msg' => 'done'], 200),
        'DELETE /fapi/v1/algoOpenOrders' => Http::response(['code' => '200', 'msg' => 'success'], 200),
    ], $log);

    $res = algoClient()->cancelOpenOrders('BTCUSDT');

    expect(array_column($log, 'key'))->toBe(['DELETE /fapi/v1/allOpenOrders', 'DELETE /fapi/v1/algoOpenOrders'])
        ->and($log[0]['query']['symbol'])->toBe('BTCUSDT')->and($log[1]['query']['symbol'])->toBe('BTCUSDT');
    expect($res['algo_cancel']['msg'])->toBe('success');
});
