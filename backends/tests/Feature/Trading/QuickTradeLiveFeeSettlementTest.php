<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\QuickTradeService;
use App\Models\Integration;
use App\Models\QuickTrade;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Verifies the real-Binance-fee reconciliation added to
 * QuickTradeService::settleLiveTrade(): it must prefer the exchange's
 * actually-reported commission/funding fee when the lookups succeed, and
 * fall back to the pre-existing static estimate — without ever throwing or
 * stalling settlement — when they don't.
 */
function liveQuickTradeWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance Live',
        'status' => 'active',
        'config_json' => ['environment' => 'live'],
        'secrets_encrypted' => Crypt::encryptString(json_encode([
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
        ])),
    ]);

    foreach ([
        'trading_mode' => 'live',
        'live_enabled' => true,
        'preferred_provider' => 'binance',
        'preferred_integration_id' => $integration->id,
        'venue_environment' => 'live',
    ] as $key => $value) {
        Setting::updateOrCreate(
            ['workspace_id' => $workspace->id, 'group' => 'trading', 'key' => $key],
            ['id' => (string) Str::uuid(), 'value_json' => $value, 'encrypted' => false]
        );
    }

    app(MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures',
        'ETHUSDT',
        2000.0,
        (int) floor(microtime(true) * 1000),
        ['stream' => 'ethusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );

    return [$user, $workspace, $integration];
}

function fakeQueryParam(string $url, string $key): ?string
{
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);

    return $query[$key] ?? null;
}

/**
 * @param  \Closure(string $url): (\Illuminate\Http\Client\Response|null)  $userTradesAndIncomeHandler
 *         Handles /fapi/v1/userTrades and /fapi/v1/income only; return null to
 *         fall through to the default entry/exit order + market-data fakes.
 */
function fakeBinanceLive(\Closure $userTradesAndIncomeHandler): void
{
    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($userTradesAndIncomeHandler) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v1/userTrades') || str_contains($url, '/fapi/v1/income')) {
            $response = $userTradesAndIncomeHandler($url);
            if ($response !== null) {
                return $response;
            }
        }

        if (str_contains($url, '/fapi/v2/balance')) {
            return Http::response([
                ['asset' => 'USDT', 'balance' => '1000.00', 'availableBalance' => '1000.00'],
            ], 200);
        }
        if (str_contains($url, '/fapi/v1/ticker/price')) {
            return Http::response(['symbol' => 'ETHUSDT', 'price' => '2000.00'], 200);
        }
        if (str_contains($url, '/fapi/v1/exchangeInfo')) {
            return Http::response([
                'symbols' => [[
                    'symbol' => 'ETHUSDT',
                    'filters' => [[
                        'filterType' => 'MARKET_LOT_SIZE',
                        'stepSize' => '0.001',
                        'minQty' => '0.001',
                    ], [
                        'filterType' => 'MIN_NOTIONAL',
                        'notional' => '20.00000000',
                    ]],
                ]],
            ], 200);
        }
        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            $isClose = str_contains((string) $request->body(), 'reduceOnly');

            return Http::response([
                'orderId' => $isClose ? '9002' : '9001',
                'avgPrice' => $isClose ? '2010.00' : '2000.00',
                'executedQty' => '0.010',
            ], 200);
        }

        return Http::response([], 200);
    });
}

function placeLiveEthQuickTrade(string $workspaceId, string $integrationId, string $userId): QuickTrade
{
    return app(QuickTradeService::class)->place([
        'workspace_id' => $workspaceId,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        // 5 candles -- these tests' subject is live-fee reconciliation at
        // settlement, not the min-horizon-candles rule (which now ships
        // enforced by default).
        'expiry_seconds' => 300,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integrationId,
        'indicator_set' => ['MACD'],
    ], $userId);
}

test('malformed funding and a missing exit fee preserve the verified entry fee', function () {
    [$user, $workspace, $integration] = liveQuickTradeWorkspace();
    fakeBinanceLive(function (string $url) {
        if (str_contains($url, '/fapi/v1/income')) {
            return Http::response([['unexpected' => 'payload']], 200);
        }
        if (str_contains($url, '/fapi/v1/userTrades')) {
            return Http::response(fakeQueryParam($url, 'orderId') === '9001'
                ? [['commission' => '0.03', 'commissionAsset' => 'USDT']]
                : [], 200);
        }
        return null;
    });
    $trade = placeLiveEthQuickTrade($workspace->id, $integration->id, $user->id);
    $settled = app(QuickTradeService::class)->settleTrade($trade->fresh());
    expect($settled->fee_source)->toBe('partial');
    expect($settled->funding_fee_actual)->toBeNull();
    expect((float) $settled->fees_total)->toEqualWithDelta(0.04005, 0.00000001);
});

test('a live trade persists its configured exit-slippage buffer at entry for an open-position cost projection', function () {
    [$user, $workspace, $integration] = liveQuickTradeWorkspace();
    config()->set('robot_trade.live_slippage_buffer_pct', 0.04);
    fakeBinanceLive(fn (string $url) => null);

    $trade = placeLiveEthQuickTrade($workspace->id, $integration->id, $user->id);

    expect((float) data_get($trade->signal_payload, 'execution_cost.exit_slippage_pct'))
        ->toEqualWithDelta(0.04, 0.0000001);
});

test('a live settlement with real commission and funding fee lookups uses real values and marks fee_source real', function () {
    [$user, $workspace, $integration] = liveQuickTradeWorkspace();

    fakeBinanceLive(function (string $url) {
        if (str_contains($url, '/fapi/v1/userTrades')) {
            $orderId = fakeQueryParam($url, 'orderId');
            $commission = $orderId === '9002' ? '0.06000000' : '0.05000000';

            return Http::response([[
                'symbol' => 'ETHUSDT',
                'id' => 1,
                'orderId' => (int) $orderId,
                'side' => $orderId === '9002' ? 'SELL' : 'BUY',
                'price' => $orderId === '9002' ? '2010.00' : '2000.00',
                'qty' => '0.010',
                'realizedPnl' => '0',
                'commission' => $commission,
                'commissionAsset' => 'USDT',
                'time' => now()->getTimestampMs(),
            ]], 200);
        }
        if (str_contains($url, '/fapi/v1/income')) {
            return Http::response([[
                'symbol' => 'ETHUSDT',
                'incomeType' => 'FUNDING_FEE',
                'income' => '-0.02000000',
                'asset' => 'USDT',
                'time' => now()->getTimestampMs(),
            ]], 200);
        }

        return null;
    });

    $quickTrade = placeLiveEthQuickTrade($workspace->id, $integration->id, $user->id);
    $settled = app(QuickTradeService::class)->settleTrade($quickTrade->fresh());

    expect($settled->status)->not->toBe('open');
    expect($settled->fee_source)->toBe('real');
    expect((float) $settled->entry_fee_actual)->toEqualWithDelta(0.05, 0.0000001);
    expect((float) $settled->exit_fee_actual)->toEqualWithDelta(0.06, 0.0000001);
    expect((float) $settled->funding_fee_actual)->toEqualWithDelta(0.02, 0.0000001);
    // gross pnl: (2010 - 2000) * 0.01 = 0.10; fees_total = 0.05 + 0.06 + 0.02 = 0.13
    expect((float) $settled->gross_pnl)->toEqualWithDelta(0.10, 0.0000001);
    expect((float) $settled->fees_total)->toEqualWithDelta(0.13, 0.0000001);
    expect((float) $settled->profit_loss)->toEqualWithDelta(-0.03, 0.0000001);
});

test('a live settlement falls back to the estimate and completes successfully when real fee lookups fail', function () {
    [$user, $workspace, $integration] = liveQuickTradeWorkspace();

    fakeBinanceLive(function (string $url) {
        if (str_contains($url, '/fapi/v1/userTrades') || str_contains($url, '/fapi/v1/income')) {
            return Http::response(['code' => -1121, 'msg' => 'Invalid symbol.'], 400);
        }

        return null;
    });

    $quickTrade = placeLiveEthQuickTrade($workspace->id, $integration->id, $user->id);
    $entryFeeEstimate = (float) $quickTrade->fees_total;

    $threw = false;
    try {
        $settled = app(QuickTradeService::class)->settleTrade($quickTrade->fresh());
    } catch (\Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    expect($settled->status)->not->toBe('open');
    expect($settled->fee_source)->toBe('estimated');
    expect($settled->entry_fee_actual)->toBeNull();
    expect($settled->exit_fee_actual)->toBeNull();
    expect($settled->funding_fee_actual)->toBeNull();
    // exit estimate: 2010 * 0.01 * 0.0005 = 0.01005; entry estimate stored at placement.
    $expectedFeesTotal = round($entryFeeEstimate + (2010 * 0.01 * 0.0005), 8);
    expect((float) $settled->fees_total)->toEqualWithDelta($expectedFeesTotal, 0.0000001);
});

test('a live settlement is marked partial when commission resolves but the funding lookup fails', function () {
    [$user, $workspace, $integration] = liveQuickTradeWorkspace();

    fakeBinanceLive(function (string $url) {
        if (str_contains($url, '/fapi/v1/userTrades')) {
            $orderId = fakeQueryParam($url, 'orderId');
            $commission = $orderId === '9002' ? '0.06000000' : '0.05000000';

            return Http::response([[
                'symbol' => 'ETHUSDT',
                'id' => 1,
                'orderId' => (int) $orderId,
                'side' => $orderId === '9002' ? 'SELL' : 'BUY',
                'price' => $orderId === '9002' ? '2010.00' : '2000.00',
                'qty' => '0.010',
                'realizedPnl' => '0',
                'commission' => $commission,
                'commissionAsset' => 'USDT',
                'time' => now()->getTimestampMs(),
            ]], 200);
        }
        if (str_contains($url, '/fapi/v1/income')) {
            return Http::response(['code' => -1000, 'msg' => 'An unknown error occurred.'], 500);
        }

        return null;
    });

    $quickTrade = placeLiveEthQuickTrade($workspace->id, $integration->id, $user->id);

    $threw = false;
    try {
        $settled = app(QuickTradeService::class)->settleTrade($quickTrade->fresh());
    } catch (\Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    expect($settled->fee_source)->toBe('partial');
    expect((float) $settled->entry_fee_actual)->toEqualWithDelta(0.05, 0.0000001);
    expect((float) $settled->exit_fee_actual)->toEqualWithDelta(0.06, 0.0000001);
    expect($settled->funding_fee_actual)->toBeNull();
    // funding lookup failed -> treated as 0 contribution to fees_total, never blocks settlement.
    expect((float) $settled->fees_total)->toEqualWithDelta(0.11, 0.0000001);
});

test('paper-mode quick trade settlement is completely unaffected by the live fee reconciliation', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 10:00:00'));

    $quickTrade = app(QuickTradeService::class)->place([
        'workspace_id' => $workspace->id,
        'symbol' => 'GOLD_OTC',
        'side' => 'buy',
        'stake_amount' => 10,
        'timeframe' => '1m',
        'expiry_seconds' => 5,
        'mode' => 'paper',
        'indicator_set' => ['RSI'],
    ], $user->id);

    $symbol = \App\Models\Symbol::where('symbol', 'GOLD_OTC')->firstOrFail();
    \App\Models\MarketTick::create([
        'id' => (string) Str::uuid(),
        'symbol_id' => $symbol->id,
        'price' => (float) $quickTrade->entry_price + 2,
        'bid' => (float) $quickTrade->entry_price + 1.9,
        'ask' => (float) $quickTrade->entry_price + 2.1,
        'volume' => 50,
        'occurred_at' => CarbonImmutable::parse('2026-03-10 10:00:06'),
        'metadata' => ['test' => true],
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 10:00:07'));

    $settled = app(QuickTradeService::class)->settleTrade($quickTrade->fresh());

    expect($settled->status)->toBe('won');
    expect($settled->fee_source)->toBe('estimated'); // column default, untouched by the paper path
    expect($settled->gross_pnl)->toBeNull();
    expect($settled->entry_fee_actual)->toBeNull();
    expect($settled->exit_fee_actual)->toBeNull();
    expect((float) $settled->funding_fee_actual)->toBe(0.0);
    expect((float) $settled->fees_total)->toBe(0.0);

    CarbonImmutable::setTestNow();
});
