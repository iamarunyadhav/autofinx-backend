<?php

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function ftAlgoSettings(): array
{
    return array_merge(config('future_trade.defaults'), [
        'liveTrading' => true, 'paperTrading' => false, 'forceTestnet' => true, 'envMode' => 'testnet',
        'envTestnetEnabled' => true, 'testnetApiUrl' => 'https://testnet.binancefuture.local',
        'testnetApiKey' => 'test-key', 'testnetApiSecret' => 'test-secret',
        'symbols' => ['ETHUSDT'], 'tradeAmountUsdt' => 25,
    ]);
}

function ftAlgoConfig(string $workspaceId, array $state = []): FutureTradeBotConfig
{
    $config = FutureTradeBotConfig::create([
        'workspace_id' => $workspaceId, 'bot_name' => 'Future Trade Bot', 'paper_trading' => false,
        'enable_live_trading' => true, 'enabled' => true, 'settings' => ftAlgoSettings(),
    ]);
    $config->state()->create(array_merge([
        'status' => 'Running', 'state' => 'LIVE_IN_POSITION', 'connection_status' => 'Connected',
        'current_symbol' => 'ETHUSDT', 'current_signal' => 'BUY_LIVE_EXECUTED', 'position_side' => 'LONG',
        'entry_price' => 2000.0, 'quantity' => 0.05, 'current_price' => 2000.0, 'confidence' => 0.61,
        'trading_day' => now()->toDateString(),
    ], $state));

    return $config->fresh('state');
}

function ftAlgoRequestLog(array &$log): \Closure
{
    return function ($request) use (&$log) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        parse_str((string) $request->body(), $form);
        $log[] = ['key' => $request->method().' '.$path, 'query' => $query, 'form' => $form];
    };
}

test('future trade SL/TP: placed through the algo endpoint end to end; a failed TP surfaces the exact Binance code, never silently', function () {
    config(['binance.conditional_order_mode' => 'algo']);
    Cache::flush();
    Log::spy();
    $workspace = Workspace::factory()->create();
    $config = ftAlgoConfig($workspace->id);
    $logical = FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id, 'workspace_id' => (string) $workspace->id, 'symbol' => 'ETHUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'LIVE_TESTNET', 'signal_key' => 'sig-algo-1',
        'entry_price' => 2000, 'quantity' => 0.05, 'opened_at' => now(),
    ]);

    $log = [];
    $record = ftAlgoRequestLog($log);
    $openIds = [];
    Http::fake(function ($request) use ($record, &$openIds) {
        $record($request);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/fapi/v1/exchangeInfo') {
            return Http::response(['symbols' => [['symbol' => 'ETHUSDT', 'filters' => [
                ['filterType' => 'PRICE_FILTER', 'tickSize' => '0.01'], ['filterType' => 'LOT_SIZE', 'stepSize' => '0.001'],
            ]]]], 200);
        }
        if ($request->method() === 'POST' && $path === '/fapi/v1/algoOrder') {
            parse_str((string) $request->body(), $form);
            $id = $form['clientAlgoId'];
            if (in_array($id, $openIds, true)) {
                // what Binance does with a clientAlgoId that is still open (unique among open orders)
                return Http::response(['code' => -4116, 'msg' => 'ClientOrderId is duplicated.'], 400);
            }
            $openIds[] = $id;

            return Http::response(['algoId' => 1000 + count($openIds), 'clientAlgoId' => $id, 'algoType' => 'CONDITIONAL', 'orderType' => $form['type'],
                'symbol' => $form['symbol'], 'side' => $form['side'], 'algoStatus' => 'NEW', 'triggerPrice' => $form['triggerPrice'], 'closePosition' => true], 200);
        }

        return Http::response(['code' => -9999, 'msg' => 'unexpected '.$request->method().' '.$path], 500);
    });

    $service = app(FutureTradeBotService::class);
    $client = new BinanceFuturesClient('test-key', 'test-secret', 'https://testnet.binancefuture.local', 'ft_algo_test');
    $m = new ReflectionMethod($service, 'placeExchangeSLTPOrders');
    $m->setAccessible(true);
    $m->invoke($service, $config, $client, $logical, 'ETHUSDT', 'LONG', 2000.0, ftAlgoSettings(), 'sig-algo-1');

    // SL went to the algo endpoint: triggerPrice, closePosition, no quantity, 36-char-safe client id, never the legacy endpoint.
    $posts = array_values(array_filter($log, fn ($r) => $r['key'] === 'POST /fapi/v1/algoOrder'));
    expect($posts[0]['form']['type'])->toBe('STOP_MARKET')
        ->and($posts[0]['form']['side'])->toBe('SELL')->and($posts[0]['form']['closePosition'])->toBe('true')
        ->and($posts[0]['form'])->toHaveKey('triggerPrice')->and($posts[0]['form'])->not->toHaveKey('quantity')
        ->and(strlen($posts[0]['form']['clientAlgoId']))->toBeLessThanOrEqual(36);
    expect(array_filter($log, fn ($r) => $r['key'] === 'POST /fapi/v1/order'))->toBe([]);

    $events = $config->fresh()->logs()->pluck('event')->all();
    expect($events)->toContain('EXCHANGE_SL_PLACED');
    $meta = $logical->fresh()->meta;
    expect($meta['sl_exchange_order_id'])->toBe('1001')->and($meta['sl_client_order_id'])->toBe($posts[0]['form']['clientAlgoId']);

    // The former shared-id defect (SL and TP both `ftx-entry-<key>`, TP rejected as a duplicate) is fixed: SL and TP now
    // carry distinct ids, so the duplicate-open-id rule of this fake exchange (same as Binance's) accepts both.
    expect($events)->toContain('EXCHANGE_TP_PLACED')->not->toContain('EXCHANGE_TP_FAILED');
    expect($posts[1]['form']['type'])->toBe('TAKE_PROFIT_MARKET')
        ->and($posts[1]['form']['clientAlgoId'])->not->toBe($posts[0]['form']['clientAlgoId']);
    expect($meta['tp_client_order_id'])->toBe($posts[1]['form']['clientAlgoId']);
    Log::shouldNotHaveReceived('critical', [\Mockery::on(fn ($m) => str_contains((string) $m, 'FT EXCHANGE STOP NOT PLACED')), \Mockery::any()]);
});

test('future trade SL/TP: a genuinely failing TP (non-id reason) is still LOUD: exact code in the log row, critical log, SL kept', function () {
    config(['binance.conditional_order_mode' => 'algo']);
    Cache::flush();
    Log::spy();
    $workspace = Workspace::factory()->create();
    $config = ftAlgoConfig($workspace->id);
    $logical = FutureTradeLogicalTrade::create([
        'future_trade_bot_config_id' => $config->id, 'workspace_id' => (string) $workspace->id, 'symbol' => 'ETHUSDT', 'side' => 'LONG',
        'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'LIVE_TESTNET', 'signal_key' => 'sig-algo-2',
        'entry_price' => 2000, 'quantity' => 0.05, 'opened_at' => now(),
    ]);
    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/fapi/v1/exchangeInfo') {
            return Http::response(['symbols' => [['symbol' => 'ETHUSDT', 'filters' => [
                ['filterType' => 'PRICE_FILTER', 'tickSize' => '0.01'], ['filterType' => 'LOT_SIZE', 'stepSize' => '0.001'],
            ]]]], 200);
        }
        parse_str((string) $request->body(), $form);
        if ($path === '/fapi/v1/algoOrder' && ($form['type'] ?? '') === 'TAKE_PROFIT_MARKET') {
            return Http::response(['code' => -2021, 'msg' => 'Order would immediately trigger.'], 400);
        }

        return Http::response(['algoId' => 1, 'clientAlgoId' => $form['clientAlgoId'] ?? 'x', 'algoType' => 'CONDITIONAL', 'orderType' => $form['type'] ?? 'STOP_MARKET',
            'symbol' => 'ETHUSDT', 'side' => 'SELL', 'algoStatus' => 'NEW', 'closePosition' => true], 200);
    });
    $service = app(FutureTradeBotService::class);
    $client = new BinanceFuturesClient('test-key', 'test-secret', 'https://testnet.binancefuture.local', 'ft_algo_test3');
    $m = new ReflectionMethod($service, 'placeExchangeSLTPOrders');
    $m->setAccessible(true);
    $m->invoke($service, $config, $client, $logical, 'ETHUSDT', 'LONG', 2000.0, ftAlgoSettings(), 'sig-algo-2');

    $events = $config->fresh()->logs()->pluck('event')->all();
    expect($events)->toContain('EXCHANGE_SL_PLACED')->toContain('EXCHANGE_TP_FAILED');
    $tpRow = $config->fresh()->logs()->where('event', 'EXCHANGE_TP_FAILED')->first();
    expect($tpRow->reason)->toContain('-2021')
        ->and($tpRow->context['binance_code'])->toBe(-2021)
        ->and($tpRow->context['protection'])->toBe('NO_EXCHANGE_SIDE_STOP_CONFIRMED');
    Log::shouldHaveReceived('critical')->withArgs(fn ($m) => str_contains($m, 'FT EXCHANGE STOP NOT PLACED'))->atLeast()->once();
});

test('future trade recover/close: algo stops are visible to the reduce-only cancel step and cancelled via the algo endpoint by algoId', function () {
    config(['binance.conditional_order_mode' => 'auto']);
    Cache::flush();
    $this->withoutMiddleware('require.2fa');
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
    ftAlgoConfig($workspace->id, ['position_side' => 'SHORT', 'quantity' => 0.017]);

    $log = [];
    $record = ftAlgoRequestLog($log);
    Http::fake(function ($request) use ($record) {
        $record($request);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/fapi/v2/positionRisk') {
            return Http::response([['symbol' => 'ETHUSDT', 'positionAmt' => '0', 'entryPrice' => '0', 'markPrice' => '2282.00', 'unRealizedProfit' => '0']], 200);
        }
        if ($path === '/fapi/v1/openOrders') {
            return Http::response([['symbol' => 'ETHUSDT', 'orderId' => '777', 'clientOrderId' => 'ft-close-stale', 'reduceOnly' => true]], 200);
        }
        if ($path === '/fapi/v1/openAlgoOrders') {
            return Http::response([['algoId' => 4242, 'clientAlgoId' => 'ftx-sl-abc', 'algoType' => 'CONDITIONAL', 'orderType' => 'STOP_MARKET',
                'symbol' => 'ETHUSDT', 'side' => 'BUY', 'algoStatus' => 'NEW', 'triggerPrice' => '2300.00', 'closePosition' => true]], 200);
        }
        if ($request->method() === 'DELETE' && $path === '/fapi/v1/algoOrder') {
            return Http::response(['algoId' => 4242, 'clientAlgoId' => 'ftx-sl-abc', 'code' => '200', 'msg' => 'success'], 200);
        }
        if ($request->method() === 'DELETE' && $path === '/fapi/v1/order') {
            return Http::response(['status' => 'CANCELED'], 200);
        }

        return Http::response([], 200);
    });

    $this->actingAs($user)
        ->postJson("/api/v1/workspaces/{$workspace->id}/future-trade/recover", ['action' => 'auto'])
        ->assertOk()
        ->assertJsonPath('cancelled_reduce_only_orders', 2);

    $deletes = array_values(array_filter($log, fn ($r) => str_starts_with($r['key'], 'DELETE ')));
    $byKey = collect($deletes)->groupBy('key');
    expect($byKey['DELETE /fapi/v1/algoOrder'])->toHaveCount(1)
        ->and($byKey['DELETE /fapi/v1/algoOrder'][0]['query']['algoId'])->toBe('4242')
        ->and($byKey['DELETE /fapi/v1/order'])->toHaveCount(1)
        ->and($byKey['DELETE /fapi/v1/order'][0]['query']['orderId'])->toBe('777');   // legacy row: numeric orderId, legacy endpoint, never the algo id space
});

test('future trade snapshot: when the algo open-orders lookup fails the open-orders snapshot is flagged unavailable, not "no stops"', function () {
    config(['binance.conditional_order_mode' => 'algo']);
    Cache::flush();
    $workspace = Workspace::factory()->create();
    ftAlgoConfig($workspace->id);
    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match ($path) {
            '/fapi/v2/positionRisk' => Http::response([['symbol' => 'ETHUSDT', 'positionAmt' => '0.05', 'entryPrice' => '2000', 'markPrice' => '2000', 'unRealizedProfit' => '0']], 200),
            '/fapi/v1/openOrders' => Http::response([], 200),
            default => Http::response(['code' => -1000, 'msg' => 'down'], 400),
        };
    });
    $service = app(FutureTradeBotService::class);
    $client = new BinanceFuturesClient('test-key', 'test-secret', 'https://testnet.binancefuture.local', 'ft_algo_test2');
    $m = new ReflectionMethod($service, 'exchangePositionSnapshot');
    $m->setAccessible(true);
    $snapshot = $m->invoke($service, $client, 'ETHUSDT');

    expect($snapshot['open_orders_snapshot_available'])->toBeFalse()->and($snapshot['snapshot_partial'])->toBeTrue()
        ->and($snapshot['position_snapshot_available'])->toBeTrue()->and($snapshot['quantity'])->toBe(0.05);
});
