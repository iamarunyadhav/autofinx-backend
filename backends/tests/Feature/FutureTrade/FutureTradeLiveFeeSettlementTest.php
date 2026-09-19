<?php

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Verifies the real-Binance-fee/funding reconciliation added to
 * FutureTradeBotService::closeLivePosition(): a confirmed live close must
 * prefer the exchange's actually-reported commission/funding fee when the
 * lookups succeed, and fall back to the pre-existing static 0.04%-per-side
 * estimate — without ever throwing or stalling settlement — when they
 * don't. Mirrors QuickTradeLiveFeeSettlementTest's equivalent coverage for
 * Quick Trade's own (earlier) real-fee fix.
 */
function futureTradeLiveFeeConfig(string $workspaceId, array $settings = []): FutureTradeBotConfig
{
    $config = FutureTradeBotConfig::create([
        'workspace_id' => $workspaceId,
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => false,
        'enable_live_trading' => true,
        'enabled' => true,
        'settings' => array_merge(config('future_trade.defaults'), [
            'signalProfile' => 'autofinx_prime_v6_1_7_crypto',
            'marketProfile' => 'Crypto',
            'symbols' => ['ETHUSDT'],
            'timeframe' => '15m',
            'tradeScoreThreshold' => 72,
            'signalSource' => 'webhook',
            'tradeAmountUsdt' => 25,
            'leverage' => 3,
            'maxLeverage' => 5,
            'liveTrading' => true,
            'paperTrading' => false,
            'forceTestnet' => true,
            'envTestnetEnabled' => true,
            'testnetApiUrl' => 'https://testnet.binancefuture.local',
            'testnetApiKey' => 'test-key',
            'testnetApiSecret' => 'test-secret',
            'allowLiveWithoutUserStream' => true,
            'stopLossPercent' => 2,
        ], $settings),
    ]);

    $config->state()->create([
        'status' => 'Running',
        'state' => 'SCANNING',
        'connection_status' => 'Connected',
        'trading_day' => now()->toDateString(),
        'trade_count_today' => 0,
    ]);

    return $config->fresh('state');
}

/**
 * @param  \Closure(string $url): (\Illuminate\Http\Client\Response|null)  $userTradesAndIncomeHandler
 *         Handles /fapi/v1/userTrades and /fapi/v1/income only; return null to
 *         fall through to the default open/close/market-data fakes.
 * @param  float  $openPositionAmt  positionAmt reported by /fapi/v2/positionRisk
 *         once the entry order has "landed" — 0 until $entryPlaced is toggled true.
 */
function fakeFutureTradeBinanceLiveClose(\Closure $userTradesAndIncomeHandler, array $state): \Closure
{
    return function (\Illuminate\Http\Client\Request $request) use ($userTradesAndIncomeHandler, &$state) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v1/userTrades') || str_contains($url, '/fapi/v1/income')) {
            $response = $userTradesAndIncomeHandler($url);
            if ($response !== null) {
                return $response;
            }
        }
        if (str_contains($url, '/fapi/v2/positionRisk')) {
            if (! $state['entryFilled']) {
                return Http::response([], 200);
            }

            return Http::response([[
                'symbol' => 'ETHUSDT',
                'positionAmt' => $state['closed'] ? '0' : '0.020',
                'entryPrice' => '2000.00',
                'markPrice' => '2005.00',
                'unRealizedProfit' => '0.10',
            ]], 200);
        }
        if (str_contains($url, '/fapi/v1/openOrders')) {
            return Http::response([], 200);
        }
        if (str_contains($url, '/fapi/v2/account')) {
            return Http::response([
                'availableBalance' => '1000.00',
                'assets' => [['asset' => 'USDT', 'availableBalance' => '1000.00']],
            ], 200);
        }
        if (str_contains($url, '/fapi/v1/exchangeInfo')) {
            return Http::response([
                'symbols' => [[
                    'symbol' => 'ETHUSDT',
                    'filters' => [[
                        'filterType' => 'LOT_SIZE',
                        'stepSize' => '0.001',
                        'minQty' => '0.001',
                    ], [
                        'filterType' => 'MIN_NOTIONAL',
                        'notional' => '20.00000000',
                    ]],
                ]],
            ], 200);
        }
        if (str_contains($url, '/fapi/v1/leverageBracket')) {
            return Http::response([
                'symbol' => 'ETHUSDT',
                'brackets' => [['bracket' => 1, 'initialLeverage' => 20, 'notionalCap' => 50000, 'notionalFloor' => 0]],
            ], 200);
        }
        if (str_contains($url, '/fapi/v1/leverage') && $request->method() === 'POST') {
            return Http::response(['leverage' => 3, 'symbol' => 'ETHUSDT'], 200);
        }
        if (str_contains($url, '/fapi/v1/marginType') && $request->method() === 'POST') {
            return Http::response(['msg' => 'success'], 200);
        }
        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            $isClose = str_contains((string) $request->body(), 'reduceOnly');
            if ($isClose) {
                $state['closed'] = true;

                return Http::response([
                    'orderId' => '998877',
                    'status' => 'FILLED',
                    'avgPrice' => '2010.00',
                    'executedQty' => '0.020',
                ], 200);
            }
            $state['entryFilled'] = true;

            return Http::response([
                'orderId' => '778899',
                'status' => 'FILLED',
                'avgPrice' => '2000.00',
                'executedQty' => '0.020',
            ], 200);
        }

        return Http::response([], 200);
    };
}

function invokeFutureTradeOpen(FutureTradeBotConfig $config, array $signal)
{
    $service = app(FutureTradeBotService::class);
    $method = new ReflectionMethod(FutureTradeBotService::class, 'openLivePositionLocked');
    $method->setAccessible(true);

    return $method->invoke($service, $config->fresh('state'), $signal);
}

function invokeFutureTradeClose(FutureTradeBotConfig $config, float $price, string $reason): void
{
    $service = app(FutureTradeBotService::class);
    $method = new ReflectionMethod(FutureTradeBotService::class, 'closeLivePosition');
    $method->setAccessible(true);

    $method->invoke($service, $config->fresh('state'), $price, $reason);
}

test('a confirmed live Future Trade close with real commission and funding fee lookups uses real values and marks fee_source real', function () {
    $workspace = Workspace::factory()->create();
    $config = futureTradeLiveFeeConfig($workspace->id);

    $state = ['entryFilled' => false, 'closed' => false];
    Http::fake(fakeFutureTradeBinanceLiveClose(function (string $url) {
        if (str_contains($url, '/fapi/v1/userTrades')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            $orderId = $query['orderId'] ?? null;
            $commission = $orderId === '998877' ? '0.06000000' : '0.05000000';

            return Http::response([[
                'symbol' => 'ETHUSDT',
                'orderId' => (int) $orderId,
                'commission' => $commission,
                'commissionAsset' => 'USDT',
                'realizedPnl' => '0',
            ]], 200);
        }
        if (str_contains($url, '/fapi/v1/income')) {
            return Http::response([[
                'symbol' => 'ETHUSDT',
                'incomeType' => 'FUNDING_FEE',
                'income' => '-0.02000000',
                'asset' => 'USDT',
            ]], 200);
        }

        return null;
    }, $state));

    $opened = invokeFutureTradeOpen($config, [
        'symbol' => 'ETHUSDT',
        'signal' => 'BUY',
        'price' => 2000.0,
        'confidence' => 0.82,
        'signal_key' => 'ethusdt-buy-fee-real',
    ]);
    expect($opened)->toBeTrue();

    $logical = FutureTradeLogicalTrade::where('future_trade_bot_config_id', $config->id)->firstOrFail();
    expect($logical->entry_exchange_order_id)->toBe('778899');
    expect($logical->status)->toBe(FutureTradeLogicalTrade::STATUS_OPEN);

    invokeFutureTradeClose($config, 2010.0, 'MANUAL_CLOSE');

    $logical = $logical->fresh();
    expect($logical->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED);
    expect($logical->fee_source)->toBe('real');
    expect((float) $logical->entry_fee_actual)->toEqualWithDelta(0.05, 0.0000001);
    expect((float) $logical->exit_fee_actual)->toEqualWithDelta(0.06, 0.0000001);
    expect((float) $logical->funding_fee_actual)->toEqualWithDelta(0.02, 0.0000001);
    // gross pnl: (2010 - 2000) * 0.020 = 0.20; fees = 0.05 + 0.06 + 0.02 = 0.13
    expect((float) $logical->gross_pnl)->toEqualWithDelta(0.20, 0.0000001);
    expect((float) $logical->fees)->toEqualWithDelta(0.13, 0.0000001);
    expect((float) $logical->net_pnl)->toEqualWithDelta(0.07, 0.0000001);
});

test('a confirmed live Future Trade close falls back to the estimate and completes successfully when real fee lookups fail', function () {
    $workspace = Workspace::factory()->create();
    $config = futureTradeLiveFeeConfig($workspace->id);

    $state = ['entryFilled' => false, 'closed' => false];
    Http::fake(fakeFutureTradeBinanceLiveClose(
        fn (string $url) => (str_contains($url, '/fapi/v1/userTrades') || str_contains($url, '/fapi/v1/income'))
            ? Http::response(['code' => -1121, 'msg' => 'Invalid symbol.'], 400)
            : null,
        $state
    ));

    $opened = invokeFutureTradeOpen($config, [
        'symbol' => 'ETHUSDT',
        'signal' => 'BUY',
        'price' => 2000.0,
        'confidence' => 0.82,
        'signal_key' => 'ethusdt-buy-fee-fallback',
    ]);
    expect($opened)->toBeTrue();

    $logical = FutureTradeLogicalTrade::where('future_trade_bot_config_id', $config->id)->firstOrFail();

    $threw = false;
    try {
        invokeFutureTradeClose($config, 2010.0, 'MANUAL_CLOSE');
    } catch (\Throwable) {
        $threw = true;
    }
    expect($threw)->toBeFalse();

    $logical = $logical->fresh();
    expect($logical->status)->toBe(FutureTradeLogicalTrade::STATUS_CLOSED);
    expect($logical->fee_source)->toBe('estimated');
    expect($logical->entry_fee_actual)->toBeNull();
    expect($logical->exit_fee_actual)->toBeNull();
    expect($logical->funding_fee_actual)->toBeNull();
    // static estimate: (entry 2000 + exit 2010) * 0.020 * 0.0004 = 0.03208
    expect((float) $logical->fees)->toEqualWithDelta(0.03208, 0.0000001);
    expect((float) $logical->net_pnl)->toEqualWithDelta(0.20 - 0.03208, 0.0000001);
});

test('a confirmed live Future Trade close is marked partial when commission resolves but the funding lookup fails', function () {
    $workspace = Workspace::factory()->create();
    $config = futureTradeLiveFeeConfig($workspace->id);

    $state = ['entryFilled' => false, 'closed' => false];
    Http::fake(fakeFutureTradeBinanceLiveClose(function (string $url) {
        if (str_contains($url, '/fapi/v1/userTrades')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
            $orderId = $query['orderId'] ?? null;
            $commission = $orderId === '998877' ? '0.06000000' : '0.05000000';

            return Http::response([[
                'symbol' => 'ETHUSDT',
                'orderId' => (int) $orderId,
                'commission' => $commission,
                'commissionAsset' => 'USDT',
                'realizedPnl' => '0',
            ]], 200);
        }
        if (str_contains($url, '/fapi/v1/income')) {
            return Http::response(['code' => -1000, 'msg' => 'An unknown error occurred.'], 500);
        }

        return null;
    }, $state));

    $opened = invokeFutureTradeOpen($config, [
        'symbol' => 'ETHUSDT',
        'signal' => 'BUY',
        'price' => 2000.0,
        'confidence' => 0.82,
        'signal_key' => 'ethusdt-buy-fee-partial',
    ]);
    expect($opened)->toBeTrue();

    $logical = FutureTradeLogicalTrade::where('future_trade_bot_config_id', $config->id)->firstOrFail();

    invokeFutureTradeClose($config, 2010.0, 'MANUAL_CLOSE');

    $logical = $logical->fresh();
    expect($logical->fee_source)->toBe('partial');
    expect((float) $logical->entry_fee_actual)->toEqualWithDelta(0.05, 0.0000001);
    expect((float) $logical->exit_fee_actual)->toEqualWithDelta(0.06, 0.0000001);
    expect($logical->funding_fee_actual)->toBeNull();
    // funding lookup failed -> treated as 0 contribution to fees, never blocks settlement.
    expect((float) $logical->fees)->toEqualWithDelta(0.11, 0.0000001);
});
