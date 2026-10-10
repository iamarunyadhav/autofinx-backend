<?php

use App\Domain\Exchange\BinanceAlgoOrderApi;
use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\FutureTrade\FutureTradeBotService;
use App\Domain\FutureTrade\FutureTradeSignalStabilityService;
use App\Domain\FutureTrade\FutureTradeUserStreamService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const PCI_KEY = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718';   // 48 hex, like generateSignalKey()

function pciSettings(): array
{
    return array_merge(config('future_trade.defaults'), [
        'liveTrading' => true, 'paperTrading' => false, 'forceTestnet' => true, 'envMode' => 'testnet',
        'envTestnetEnabled' => true, 'testnetApiUrl' => 'https://testnet.binancefuture.local',
        'testnetApiKey' => 'test-key', 'testnetApiSecret' => 'test-secret',
        'symbols' => ['ETHUSDT'], 'tradeAmountUsdt' => 25,
    ]);
}

function pciConfig(string $workspaceId): FutureTradeBotConfig
{
    $config = FutureTradeBotConfig::create([
        'workspace_id' => $workspaceId, 'bot_name' => 'Future Trade Bot', 'paper_trading' => false,
        'enable_live_trading' => true, 'enabled' => true, 'settings' => pciSettings(),
    ]);
    $config->state()->create([
        'status' => 'Running', 'state' => 'LIVE_IN_POSITION', 'connection_status' => 'Connected',
        'current_symbol' => 'ETHUSDT', 'current_signal' => 'BUY_LIVE_EXECUTED', 'position_side' => 'LONG',
        'entry_price' => 2000.0, 'quantity' => 0.05, 'current_price' => 2000.0, 'confidence' => 0.61,
        'trading_day' => now()->toDateString(),
    ]);

    return $config->fresh('state');
}

function pciTrade(FutureTradeBotConfig $config, array $attrs = []): FutureTradeLogicalTrade
{
    return FutureTradeLogicalTrade::create(array_merge([
        'future_trade_bot_config_id' => $config->id, 'workspace_id' => (string) $config->workspace_id, 'symbol' => 'ETHUSDT',
        'side' => 'LONG', 'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'mode' => 'LIVE_TESTNET', 'signal_key' => PCI_KEY,
        'entry_price' => 2000, 'quantity' => 0.05, 'opened_at' => now(),
    ], $attrs));
}

function pciFill(string $clientId, string $side = 'SELL', string $type = 'STOP_MARKET', float $price = 1980.0): array
{
    return ['e' => 'ORDER_TRADE_UPDATE', 'o' => [
        's' => 'ETHUSDT', 'c' => $clientId, 'i' => '9001', 'X' => 'FILLED', 'x' => 'TRADE', 'S' => $side, 'o' => $type, 'ot' => $type,
        'ap' => $price, 'z' => 0.05, 'rp' => -1.0, 'n' => 0.02,
    ]];
}

/* ----------------------------------------------------------------- id format */

test('client ids: entry and exit formats are unchanged, SL/TP/trailing each get a distinct prefix', function () {
    $svc = new FutureTradeSignalStabilityService();
    $short = substr(PCI_KEY, 0, 24);

    expect($svc->deterministicClientOrderId(PCI_KEY, 'entry'))->toBe('ftx-entry-'.$short)
        ->and($svc->deterministicClientOrderId(PCI_KEY))->toBe('ftx-entry-'.$short)
        ->and($svc->deterministicClientOrderId(PCI_KEY, 'exit'))->toBe('ftx-exit-'.$short)
        ->and($svc->deterministicClientOrderId(PCI_KEY, 'sl'))->toBe('ftx-sl-'.$short)
        ->and($svc->deterministicClientOrderId(PCI_KEY, 'tp'))->toBe('ftx-tp-'.$short)
        ->and($svc->deterministicClientOrderId(PCI_KEY, 'sl-t1'))->toBe('ftx-tsl-1-'.$short);

    $ids = [
        $svc->deterministicClientOrderId(PCI_KEY, 'entry'), $svc->deterministicClientOrderId(PCI_KEY, 'exit'),
        $svc->deterministicClientOrderId(PCI_KEY, 'sl'), $svc->deterministicClientOrderId(PCI_KEY, 'tp'),
        $svc->deterministicClientOrderId(PCI_KEY, 'sl-t1'), $svc->deterministicClientOrderId(PCI_KEY, 'sl-t2'),
    ];
    expect(array_unique($ids))->toHaveCount(count($ids));
});

test('client ids: deterministic per signal (retry => same ids), different signals differ, every id <= 36 chars and algo-regex valid', function () {
    $svc = new FutureTradeSignalStabilityService();
    $other = hash('sha256', 'another signal');

    foreach (['entry', 'exit', 'sl', 'tp', 'sl-t1', 'sl-t7', 'sl-t99999'] as $kind) {
        $a = $svc->deterministicClientOrderId(PCI_KEY, $kind);
        expect($svc->deterministicClientOrderId(PCI_KEY, $kind))->toBe($a)
            ->and($svc->deterministicClientOrderId($other, $kind))->not->toBe($a)
            ->and(strlen($a))->toBeLessThanOrEqual(36)
            ->and(BinanceAlgoOrderApi::isValidClientAlgoId($a))->toBeTrue();
    }
});

test('client ids: trailing replacements are unique per cycle, stay valid for large counters, and non-hex keys are sanitised', function () {
    $svc = new FutureTradeSignalStabilityService();
    $seen = [];
    for ($n = 1; $n <= 500; $n++) {
        $id = $svc->deterministicClientOrderId(PCI_KEY, 'sl-t'.$n);
        expect(strlen($id))->toBeLessThanOrEqual(36)->and(BinanceAlgoOrderApi::isValidClientAlgoId($id))->toBeTrue();
        $seen[$id] = true;
    }
    expect($seen)->toHaveCount(500)->and(array_key_exists($svc->deterministicClientOrderId(PCI_KEY, 'sl'), $seen))->toBeFalse();

    $big = $svc->deterministicClientOrderId(PCI_KEY, 'sl-t123456789');
    expect(strlen($big))->toBe(36)->and(BinanceAlgoOrderApi::isValidClientAlgoId($big))->toBeTrue();

    $weird = $svc->deterministicClientOrderId('sltp-ab cd|ef?gh#ijkl', 'sl');
    expect(BinanceAlgoOrderApi::isValidClientAlgoId($weird))->toBeTrue()->and($weird)->not->toContain(' ');

    expect(fn () => $svc->deterministicClientOrderId(PCI_KEY, 'bogus'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $svc->deterministicClientOrderId(PCI_KEY, 'sl-t0'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $svc->deterministicClientOrderId('', 'sl'))->toThrow(InvalidArgumentException::class);
});

test('client ids: prefix classification', function () {
    expect(FutureTradeSignalStabilityService::protectiveKindFromClientOrderId('ftx-sl-abc'))->toBe('SL')
        ->and(FutureTradeSignalStabilityService::protectiveKindFromClientOrderId('ftx-tsl-2-abc'))->toBe('SL')
        ->and(FutureTradeSignalStabilityService::protectiveKindFromClientOrderId('ftx-tp-abc'))->toBe('TP')
        ->and(FutureTradeSignalStabilityService::protectiveKindFromClientOrderId('ftx-entry-abc'))->toBeNull()
        ->and(FutureTradeSignalStabilityService::protectiveKindFromClientOrderId('ftx-exit-abc'))->toBeNull();
});

/* ------------------------------------------------- end to end SL + TP placement */

function pciFakeExchange(array &$posts, string $mode): void
{
    $open = [];
    Http::fake(function ($request) use (&$posts, &$open, $mode) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) $request->body(), $form);
        if ($path === '/fapi/v1/exchangeInfo') {
            return Http::response(['symbols' => [['symbol' => 'ETHUSDT', 'filters' => [
                ['filterType' => 'PRICE_FILTER', 'tickSize' => '0.01'], ['filterType' => 'LOT_SIZE', 'stepSize' => '0.001'],
            ]]]], 200);
        }
        if ($request->method() === 'POST' && $path === '/fapi/v1/algoOrder' && $mode === 'algo') {
            $posts[] = $form;
            if (in_array($form['clientAlgoId'], $open, true)) {
                return Http::response(['code' => -4116, 'msg' => 'ClientOrderId is duplicated.'], 400);
            }
            $open[] = $form['clientAlgoId'];

            return Http::response(['algoId' => 500 + count($open), 'clientAlgoId' => $form['clientAlgoId'], 'algoType' => 'CONDITIONAL',
                'orderType' => $form['type'], 'symbol' => 'ETHUSDT', 'side' => $form['side'], 'algoStatus' => 'NEW', 'closePosition' => true], 200);
        }
        if ($request->method() === 'POST' && $path === '/fapi/v1/order' && $mode === 'legacy') {
            $posts[] = $form;
            if (in_array($form['newClientOrderId'], $open, true)) {
                return Http::response(['code' => -4015, 'msg' => 'Client order id is not valid.'], 400);
            }
            $open[] = $form['newClientOrderId'];

            return Http::response(['orderId' => 700 + count($open), 'clientOrderId' => $form['newClientOrderId'], 'status' => 'NEW'], 200);
        }

        return Http::response(['code' => -9999, 'msg' => 'unexpected '.$request->method().' '.$path], 500);
    });
}

foreach (['algo', 'legacy'] as $pciMode) {
    test("future trade SL+TP placement ({$pciMode} mode): both protective orders are accepted with distinct ids; EXCHANGE_TP_FAILED does not occur", function () use ($pciMode) {
        config(['binance.conditional_order_mode' => $pciMode]);
        Cache::flush();
        $workspace = Workspace::factory()->create();
        $config = pciConfig($workspace->id);
        $logical = pciTrade($config);

        $posts = [];
        pciFakeExchange($posts, $pciMode);
        $client = new BinanceFuturesClient('test-key', 'test-secret', 'https://testnet.binancefuture.local', 'pci_'.$pciMode);
        $service = app(FutureTradeBotService::class);
        $m = new ReflectionMethod($service, 'placeExchangeSLTPOrders');
        $m->setAccessible(true);
        $m->invoke($service, $config, $client, $logical, 'ETHUSDT', 'LONG', 2000.0, pciSettings(), PCI_KEY);

        $events = $config->fresh()->logs()->pluck('event')->all();
        expect($events)->toContain('EXCHANGE_SL_PLACED')->toContain('EXCHANGE_TP_PLACED')
            ->not->toContain('EXCHANGE_SL_FAILED')->not->toContain('EXCHANGE_TP_FAILED');
        expect($posts)->toHaveCount(2);

        $idKey = $pciMode === 'algo' ? 'clientAlgoId' : 'newClientOrderId';
        $types = array_column($posts, 'type');
        $ids = array_column($posts, $idKey);
        $short = substr(PCI_KEY, 0, 24);
        expect($types)->toBe(['STOP_MARKET', 'TAKE_PROFIT_MARKET'])->and($ids)->toBe(['ftx-sl-'.$short, 'ftx-tp-'.$short]);
        foreach ($ids as $id) {
            expect(strlen($id))->toBeLessThanOrEqual(36)->and(BinanceAlgoOrderApi::isValidClientAlgoId($id))->toBeTrue();
        }

        $meta = $logical->fresh()->meta;
        expect($meta['sl_client_order_id'])->toBe('ftx-sl-'.$short)->and($meta['tp_client_order_id'])->toBe('ftx-tp-'.$short)
            ->and($meta['sl_client_order_id'])->not->toBe('ftx-entry-'.$short);
        if ($pciMode === 'algo') {
            expect($meta['sl_algo'])->toBeTrue()->and($meta['tp_algo'])->toBeTrue();
        }

        // Retry of the same signal derives the same ids (idempotent: the exchange would answer duplicate, not a 2nd order).
        $svc = new FutureTradeSignalStabilityService();
        expect($svc->deterministicClientOrderId(PCI_KEY, 'sl'))->toBe($ids[0])->and($svc->deterministicClientOrderId(PCI_KEY, 'tp'))->toBe($ids[1]);
    });
}

/* -------------------------------------------------- user stream attribution */

function pciSiblingFake(array &$deletes): void
{
    Http::fake(function ($request) use (&$deletes) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        if ($request->method() === 'DELETE') {
            $deletes[] = ['path' => $path, 'query' => $query];

            return $path === '/fapi/v1/algoOrder'
                ? Http::response(['algoId' => 1, 'clientAlgoId' => $query['clientAlgoId'] ?? '', 'code' => '200', 'msg' => 'success'], 200)
                : Http::response(['status' => 'CANCELED', 'clientOrderId' => $query['origClientOrderId'] ?? ''], 200);
        }

        return Http::response([], 200);
    });
}

test('user stream: an SL fill is attributed to the SL (not entry, not TP) and the TP sibling is cancelled via the algo API', function () {
    config(['binance.conditional_order_mode' => 'auto']);
    Cache::flush();
    $config = pciConfig((string) Workspace::factory()->create()->id);
    $short = substr(PCI_KEY, 0, 24);
    $trade = pciTrade($config, ['entry_client_order_id' => 'ftx-entry-'.$short, 'meta' => [
        'sl_client_order_id' => 'ftx-sl-'.$short, 'tp_client_order_id' => 'ftx-tp-'.$short, 'sl_algo' => true, 'tp_algo' => true,
    ]]);
    $deletes = [];
    pciSiblingFake($deletes);

    expect(app(FutureTradeUserStreamService::class)->ingestEvent($config, pciFill('ftx-sl-'.$short, 'SELL', 'STOP_MARKET', 1980.0)))->toBeTrue();

    $trade->refresh();
    expect($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED)
        ->and($trade->exit_client_order_id)->toBe('ftx-sl-'.$short)
        ->and((float) $trade->exit_price)->toEqualWithDelta(1980.0, 0.0001)
        ->and((float) $trade->entry_price)->toEqualWithDelta(2000.0, 0.0001);   // untouched: not read as an entry fill
    expect($config->fresh('state')->state->current_signal)->toBe('EXCHANGE_SL_FILLED');
    expect($deletes)->toHaveCount(1)
        ->and($deletes[0]['path'])->toBe('/fapi/v1/algoOrder')
        ->and($deletes[0]['query']['clientAlgoId'])->toBe('ftx-tp-'.$short);
});

test('user stream: a TP fill is attributed to the TP and the (trailed) SL sibling is cancelled', function () {
    config(['binance.conditional_order_mode' => 'auto']);
    Cache::flush();
    $config = pciConfig((string) Workspace::factory()->create()->id);
    $short = substr(PCI_KEY, 0, 24);
    $trade = pciTrade($config, ['entry_client_order_id' => 'ftx-entry-'.$short, 'meta' => [
        'sl_client_order_id' => 'ftx-tsl-3-'.$short, 'tp_client_order_id' => 'ftx-tp-'.$short, 'sl_algo' => true, 'tp_algo' => true,
    ]]);
    $deletes = [];
    pciSiblingFake($deletes);

    expect(app(FutureTradeUserStreamService::class)->ingestEvent($config, pciFill('ftx-tp-'.$short, 'SELL', 'TAKE_PROFIT_MARKET', 2050.0)))->toBeTrue();

    expect($trade->fresh()->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED)
        ->and($trade->fresh()->exit_client_order_id)->toBe('ftx-tp-'.$short);
    expect($config->fresh('state')->state->current_signal)->toBe('EXCHANGE_TP_FILLED');
    expect($deletes)->toHaveCount(1)->and($deletes[0]['query']['clientAlgoId'])->toBe('ftx-tsl-3-'.$short);
});

test('user stream: sibling of a legacy-endpoint stop is cancelled on the legacy API (sl_algo/tp_algo = false)', function () {
    config(['binance.conditional_order_mode' => 'auto']);
    Cache::flush();
    $config = pciConfig((string) Workspace::factory()->create()->id);
    $short = substr(PCI_KEY, 0, 24);
    pciTrade($config, ['meta' => ['sl_client_order_id' => 'ftx-sl-'.$short, 'tp_client_order_id' => 'ftx-tp-'.$short, 'sl_algo' => false, 'tp_algo' => false]]);
    $deletes = [];
    pciSiblingFake($deletes);

    expect(app(FutureTradeUserStreamService::class)->ingestEvent($config, pciFill('ftx-sl-'.$short)))->toBeTrue();

    expect($deletes)->toHaveCount(1)->and($deletes[0]['path'])->toBe('/fapi/v1/order')
        ->and($deletes[0]['query']['origClientOrderId'])->toBe('ftx-tp-'.$short);
});

test('user stream: a new-format SL fill that races the meta write is still attributed by prefix', function () {
    config(['binance.conditional_order_mode' => 'legacy']);
    Cache::flush();
    $config = pciConfig((string) Workspace::factory()->create()->id);
    $short = substr(PCI_KEY, 0, 24);
    $trade = pciTrade($config, ['meta' => null]);
    $deletes = [];
    pciSiblingFake($deletes);

    expect(app(FutureTradeUserStreamService::class)->ingestEvent($config, pciFill('ftx-sl-'.$short)))->toBeTrue();
    expect($trade->fresh()->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED);
    expect($config->fresh('state')->state->current_signal)->toBe('EXCHANGE_SL_FILLED');
    expect($deletes)->toBe([]);
});

test('user stream BACKWARD COMPAT: old position whose SL id equals the entry id: SL fill closes it (not an entry fill); real entry fill is still an entry fill', function () {
    config(['binance.conditional_order_mode' => 'legacy']);
    Cache::flush();
    $config = pciConfig((string) Workspace::factory()->create()->id);
    $old = 'ftx-entry-'.substr(PCI_KEY, 0, 24);
    $trade = pciTrade($config, ['entry_client_order_id' => $old, 'status' => FutureTradeLogicalTrade::STATUS_OPEN, 'meta' => ['sl_client_order_id' => $old]]);
    $deletes = [];
    pciSiblingFake($deletes);
    $svc = app(FutureTradeUserStreamService::class);

    // opening-side event with the shared id is the ENTRY fill (unchanged behaviour)
    $entryEvt = pciFill($old, 'BUY', 'MARKET', 2001.0);
    $entryEvt['o']['rp'] = 0;
    expect($svc->ingestEvent($config, $entryEvt))->toBeTrue();
    expect($trade->fresh()->status)->toBe(FutureTradeLogicalTrade::STATUS_OPEN)
        ->and((float) $trade->fresh()->entry_price)->toEqualWithDelta(2001.0, 0.0001);

    // closing-side event with the shared id is the old SL
    expect($svc->ingestEvent($config, pciFill($old, 'SELL', 'STOP_MARKET', 1970.0)))->toBeTrue();
    $trade->refresh();
    expect($trade->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED)
        ->and((float) $trade->entry_price)->toEqualWithDelta(2001.0, 0.0001)
        ->and((float) $trade->exit_price)->toEqualWithDelta(1970.0, 0.0001);
    expect($config->fresh('state')->state->current_signal)->toBe('EXCHANGE_SL_FILLED');
    expect($deletes)->toBe([]);   // sibling id == own id: never "cancel" the order that just filled
});

test('user stream BACKWARD COMPAT: both slots hold the shared old id: order type decides SL vs TP', function () {
    config(['binance.conditional_order_mode' => 'legacy']);
    Cache::flush();
    $config = pciConfig((string) Workspace::factory()->create()->id);
    $old = 'ftx-entry-'.substr(PCI_KEY, 0, 24);
    $trade = pciTrade($config, ['entry_client_order_id' => $old, 'meta' => ['sl_client_order_id' => $old, 'tp_client_order_id' => $old]]);
    $deletes = [];
    pciSiblingFake($deletes);

    expect(app(FutureTradeUserStreamService::class)->ingestEvent($config, pciFill($old, 'SELL', 'TAKE_PROFIT_MARKET', 2040.0)))->toBeTrue();
    expect($trade->fresh()->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED);
    expect($config->fresh('state')->state->current_signal)->toBe('EXCHANGE_TP_FILLED');
    expect($deletes)->toBe([]);
});

test('user stream: re-delivered SL FILLED event does not double-process', function () {
    config(['binance.conditional_order_mode' => 'legacy']);
    Cache::flush();
    $config = pciConfig((string) Workspace::factory()->create()->id);
    $short = substr(PCI_KEY, 0, 24);
    $trade = pciTrade($config, ['meta' => ['sl_client_order_id' => 'ftx-sl-'.$short, 'tp_client_order_id' => 'ftx-tp-'.$short]]);
    $deletes = [];
    pciSiblingFake($deletes);
    $svc = app(FutureTradeUserStreamService::class);

    expect($svc->ingestEvent($config, pciFill('ftx-sl-'.$short)))->toBeTrue();
    $fees = (float) $trade->fresh()->fees;
    expect($svc->ingestEvent($config, pciFill('ftx-sl-'.$short)))->toBeFalse();
    expect((float) $trade->fresh()->fees)->toEqualWithDelta($fees, 0.0000001)->and($deletes)->toHaveCount(1);
});

/* ------------------------------------------------------- trailing SL cycles */

test('trailing SL: replacement ids differ every cycle, differ from SL/TP/entry, old stop is cancelled before the new one is placed, old position migrates', function () {
    config(['binance.conditional_order_mode' => 'algo']);
    Cache::flush();
    $config = pciConfig((string) Workspace::factory()->create()->id);
    $short = substr(PCI_KEY, 0, 24);
    $trade = pciTrade($config, ['meta' => [
        'sl_client_order_id' => 'ftx-sl-'.$short, 'tp_client_order_id' => 'ftx-tp-'.$short, 'sl_base_key' => PCI_KEY,
        'sl_algo' => true, 'sl_update_peak' => 2000.0,
    ]]);

    $calls = [];
    Http::fake(function ($request) use (&$calls) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        parse_str((string) $request->body(), $form);
        if ($path === '/fapi/v1/exchangeInfo') {
            return Http::response(['symbols' => [['symbol' => 'ETHUSDT', 'filters' => [
                ['filterType' => 'PRICE_FILTER', 'tickSize' => '0.01'], ['filterType' => 'LOT_SIZE', 'stepSize' => '0.001'],
            ]]]], 200);
        }
        if ($request->method() === 'DELETE' && $path === '/fapi/v1/algoOrder') {
            $calls[] = 'cancel:'.$query['clientAlgoId'];

            return Http::response(['algoId' => 1, 'clientAlgoId' => $query['clientAlgoId'], 'code' => '200', 'msg' => 'success'], 200);
        }
        if ($request->method() === 'POST' && $path === '/fapi/v1/algoOrder') {
            $calls[] = 'place:'.$form['clientAlgoId'];

            return Http::response(['algoId' => 77, 'clientAlgoId' => $form['clientAlgoId'], 'algoType' => 'CONDITIONAL', 'orderType' => 'STOP_MARKET',
                'symbol' => 'ETHUSDT', 'side' => 'SELL', 'algoStatus' => 'NEW', 'closePosition' => true], 200);
        }

        return Http::response(['code' => -9999, 'msg' => 'unexpected '.$path], 500);
    });

    $service = app(FutureTradeBotService::class);
    $m = new ReflectionMethod($service, 'maybeUpdateExchangeTrailingSL');
    $m->setAccessible(true);
    $settings = array_merge(pciSettings(), ['trailingTakeProfitPercent' => 0.5]);
    $state = $config->fresh('state')->state;

    $m->invoke($service, $config->fresh('state'), $state, 2100.0, $settings);   // cycle 1
    $m->invoke($service, $config->fresh('state'), $state, 2200.0, $settings);   // cycle 2

    expect($calls)->toBe([
        'cancel:ftx-sl-'.$short, 'place:ftx-tsl-1-'.$short,
        'cancel:ftx-tsl-1-'.$short, 'place:ftx-tsl-2-'.$short,
    ]);
    $meta = $trade->fresh()->meta;
    expect($meta['sl_client_order_id'])->toBe('ftx-tsl-2-'.$short)->and($meta['sl_update_count'])->toBe(2)
        ->and($meta['tp_client_order_id'])->toBe('ftx-tp-'.$short)->and($meta['sl_algo'])->toBeTrue();
});
