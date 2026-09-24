<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\Providers\BinanceTimedFuturesProvider;
use App\Domain\Trading\QuickTradeResearchService;
use App\Models\Integration;
use App\Models\QuickTrade;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Gap-checklist N3 -- stake is MARGIN, notional = stake x leverage, rounded
 * down to the exchange qty step exactly as placement does. These tests pin
 * the forecast/cost payload the Quick Trade UI renders:
 *   - position_sizing exposes stake_margin / leverage / notional / quantity
 *   - net_edge USDT figures are computed on the ROUNDED leveraged notional
 *   - percent gates (required_move_pct, cost_pct, trade_decision) do NOT
 *     change with leverage
 *   - the rounding rule is the one placement itself uses (same helper).
 */
beforeEach(function () {
    config()->set('trading.binance_futures_taker_fee_rate', 0.0005);
    config()->set('robot_trade.live_slippage_buffer_pct', 0.04);
    config()->set('robot_trade.live_min_edge_cost_multiplier', 2.0);
    config()->set('robot_trade.live_min_expected_net_usdt', 0.03);
    config()->set('quick_trade.fee_model', 'taker');
});

/** BTCUSDT-like filters: step 0.001, min qty 0.001, min notional 100. */
function n3FakeExchangeInfo(bool $fail = false): void
{
    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($fail) {
        if (str_contains($request->url(), '/fapi/v1/exchangeInfo')) {
            if ($fail) {
                return Http::response(['msg' => 'down'], 503);
            }

            return Http::response(['symbols' => [[
                'symbol' => 'BTCUSDT',
                'filters' => [
                    ['filterType' => 'MARKET_LOT_SIZE', 'stepSize' => '0.001', 'minQty' => '0.001'],
                    ['filterType' => 'MIN_NOTIONAL', 'notional' => '100'],
                ],
            ]]], 200);
        }

        return Http::response([], 200);
    });
}

/** 40 flat-ish candles whose LAST close is exactly 76,000 (no timestamps, so the last supplied close is the reference price). */
function n3Candles(): array
{
    $rows = [];
    for ($i = 0; $i < 40; $i++) {
        $close = $i === 39 ? 76000.0 : 76000.0 + (($i % 2 === 0) ? 15.0 : -15.0);
        $rows[] = ['open' => $close - 10, 'high' => $close + 40, 'low' => $close - 40, 'close' => $close, 'volume' => 900.0];
    }

    return $rows;
}

function n3Forecast(array $overrides = []): \Illuminate\Testing\TestResponse
{
    test()->actingAs(User::factory()->create());

    return test()->postJson('/api/v1/quick-trade/forecast', array_merge([
        'candles' => n3Candles(),
        'timeframe' => '5m',
        'expiry_seconds' => 3600,
        'stake_amount' => 180,
        'symbol' => 'BTCUSDT',
        'exchange' => 'binance_futures',
        'mode' => 'live',
    ], $overrides))->assertOk();
}

dataset('leverage_cases', [
    // leverage, expected qty (floor to 0.001), expected real notional @76,000
    '1x' => [1, 0.002, 152.0],
    '5x' => [5, 0.011, 836.0],
    '10x' => [10, 0.023, 1748.0],
]);

test('position_sizing and net_edge use the leveraged notional after qty-step rounding', function (int $leverage, float $qty, float $notional) {
    n3FakeExchangeInfo();
    $response = n3Forecast(['leverage' => $leverage]);

    $sizing = $response->json('position_sizing');
    expect($sizing['stake_margin'])->toEqual(180);
    expect($sizing['leverage'])->toEqual($leverage);
    expect($sizing['notional_target'])->toEqual(180 * $leverage);
    expect($sizing['rounding'])->toBe('exchange_step');
    expect($sizing['step_size'])->toEqual(0.001);
    expect((float) $sizing['quantity'])->toEqualWithDelta($qty, 1e-9);
    expect((float) $sizing['notional'])->toEqualWithDelta($notional, 1e-6);
    expect($sizing['below_exchange_minimum'])->toBeFalse();

    $edge = $response->json('net_edge');
    expect((float) $edge['notional'])->toEqualWithDelta($notional, 1e-6);
    expect((float) $edge['stake_margin'])->toEqual(180.0);
    // Round-trip taker fee 0.10% of notional; with the 0.04% slippage
    // buffer the modelled cost is 0.14% of notional.
    expect((float) $edge['round_trip_fee_usdt'])->toEqualWithDelta($notional * 0.001, 1e-6);
    expect((float) $edge['actual_cost_usdt'])->toEqualWithDelta($notional * 0.0014, 1e-6);
    expect((float) $edge['round_trip_fee_pct_of_margin'])->toEqualWithDelta($notional * 0.001 / 180 * 100, 1e-4);
    expect((float) $edge['actual_cost_pct_of_margin'])->toEqualWithDelta($notional * 0.0014 / 180 * 100, 1e-4);
    // Gross/net USDT scale with the notional, not the margin. (Tolerance
    // covers expected_move_pct being published rounded to 4 dp.)
    expect((float) $edge['expected_gross_usdt'])->toEqualWithDelta($notional * ((float) $edge['expected_move_pct'] / 100), $notional * 0.000001);

    // Percent gates are leverage-independent.
    expect((float) $edge['required_move_pct'])->toEqualWithDelta(0.28, 1e-9);
    expect((float) $response->json('forecast.trade_decision.required_move_pct'))->toEqualWithDelta(0.28, 1e-9);
    expect((float) $response->json('forecast.trade_decision.cost_pct'))->toEqualWithDelta(0.14, 1e-9);
})->with('leverage_cases');

test('a 10x trade visibly costs about 1% of margin in fees round trip', function () {
    n3FakeExchangeInfo();
    $edge = n3Forecast(['leverage' => 10])->json('net_edge');

    // 1748 notional x 0.10% = 1.748 USDT = 0.971% of the 180 margin.
    expect((float) $edge['round_trip_fee_pct_of_margin'])->toEqualWithDelta(0.971111, 1e-4);
});

test('the trade decision and percent gates are identical at 1x, 5x and 10x', function () {
    n3FakeExchangeInfo();
    $decisions = collect([1, 5, 10])->map(fn ($l) => n3Forecast(['leverage' => $l])->json('forecast.trade_decision'));

    expect($decisions[1])->toEqual($decisions[0]);
    expect($decisions[2])->toEqual($decisions[0]);
});

test('omitting leverage keeps the legacy unleveraged net_edge numbers and never calls the exchange', function () {
    Http::fake();
    $response = n3Forecast();

    expect($response->json('position_sizing.rounding'))->toBe('not_requested');
    expect($response->json('position_sizing.leverage'))->toEqual(1);
    expect((float) $response->json('net_edge.actual_cost_usdt'))->toEqualWithDelta(180 * 0.0014, 1e-9);
    Http::assertNothingSent();
});

test('paper mode ignores leverage exactly like place() does', function () {
    Http::fake();
    $response = n3Forecast(['mode' => 'paper', 'leverage' => 10]);

    expect($response->json('position_sizing.leverage'))->toEqual(1);
    expect($response->json('position_sizing.rounding'))->toBe('not_applicable_paper');
    expect((float) $response->json('net_edge.notional'))->toEqual(180.0);
    Http::assertNothingSent();
});

test('a stake below the exchange minimum after rounding is flagged, never shown as a zero-cost trade', function () {
    n3FakeExchangeInfo();
    // 20 USDT at 1x / 76,000 = 0.00026 BTC < 0.001 min qty.
    $response = n3Forecast(['stake_amount' => 20, 'leverage' => 1]);

    expect($response->json('position_sizing.below_exchange_minimum'))->toBeTrue();
    expect((float) $response->json('position_sizing.quantity'))->toEqual(0.0);
    // The cost estimate falls back to the target notional, not zero.
    expect((float) $response->json('net_edge.notional'))->toEqual(20.0);
});

test('when exchange filters are unavailable the notional is the unrounded target and says so', function () {
    n3FakeExchangeInfo(fail: true);
    $response = n3Forecast(['leverage' => 5]);

    expect($response->json('position_sizing.rounding'))->toBe('unavailable');
    expect((float) $response->json('position_sizing.notional'))->toEqual(900.0);
    expect((float) $response->json('net_edge.notional'))->toEqual(900.0);
});

test('the display rounding helper is the same rule placement uses', function () {
    expect(BinanceTimedFuturesProvider::floorToStep(0.0236842, 0.001))->toEqual(0.023);
    expect(BinanceTimedFuturesProvider::floorToStep(0.0115, 0.001))->toEqual(0.011);
    expect(BinanceTimedFuturesProvider::floorToStep(0.45, 0.001))->toEqual(0.45);
});

// ── placement consistency: the estimate's qty is the qty actually sent ──

function n3LiveWorkspace(): array
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
        'secrets_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'k', 'api_secret' => 's'])),
    ]);
    foreach ([
        'trading_mode' => 'live', 'live_enabled' => true, 'preferred_provider' => 'binance',
        'preferred_integration_id' => $integration->id, 'venue_environment' => 'live',
    ] as $key => $value) {
        Setting::updateOrCreate(
            ['workspace_id' => $workspace->id, 'group' => 'trading', 'key' => $key],
            ['id' => (string) Str::uuid(), 'value_json' => $value, 'encrypted' => false]
        );
    }
    app(MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures', 'ETHUSDT', 2000.0, (int) floor(microtime(true) * 1000),
        ['stream' => 'ethusdt@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );
    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();
        if (str_contains($url, '/fapi/v2/balance')) {
            return Http::response([['asset' => 'USDT', 'balance' => '1000.00', 'availableBalance' => '1000.00']], 200);
        }
        if (str_contains($url, '/fapi/v1/ticker/price')) {
            return Http::response(['symbol' => 'ETHUSDT', 'price' => '2000.00'], 200);
        }
        if (str_contains($url, '/fapi/v1/exchangeInfo')) {
            return Http::response(['symbols' => [[
                'symbol' => 'ETHUSDT',
                'filters' => [
                    ['filterType' => 'MARKET_LOT_SIZE', 'stepSize' => '0.001', 'minQty' => '0.001'],
                    ['filterType' => 'MIN_NOTIONAL', 'notional' => '20.00000000'],
                ],
            ]]], 200);
        }
        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            return Http::response(['orderId' => '9001', 'avgPrice' => '2000.00', 'executedQty' => '0.011'], 200);
        }

        return Http::response([], 200);
    });

    return [$user, $workspace, $integration];
}

function n3BuySnapshot(): array
{
    return [
        'schema_version' => 1,
        'captured_at' => now()->toIso8601String(),
        'primary' => ['available' => true, 'bias' => 'BUY', 'decision_trend' => 'BUY', 'horizon_seconds' => 300, 'current_price' => 2000,
            'trade_decision' => ['value' => 'BUY', 'fee_aware' => true, 'required_move_pct' => 0.28, 'max_achievable_move_pct' => 0.5, 'cost_pct' => 0.14, 'gate_reason' => null]],
        'direction_prediction' => ['value' => 'BUY', 'fee_aware' => false, 'available' => true],
        'trade_decision_prediction' => ['value' => 'BUY', 'fee_aware' => true, 'available' => true],
    ];
}

test('the quantity the estimate shows is the quantity placement sends to the exchange', function () {
    [$user, $workspace, $integration] = n3LiveWorkspace();
    config()->set('quick_trade.block_live_against_decision', true);
    $this->mock(QuickTradeResearchService::class, fn ($mock) => $mock->shouldReceive('capture')->andReturn(n3BuySnapshot()));

    // 23 USDT at 1x / 2000 = 0.0115 ETH -> floored to 0.011.
    $estimate = app(BinanceTimedFuturesProvider::class)->sizingEstimate('ETHUSDT', 23.0, 2000.0);
    expect($estimate['quantity'])->toEqual(0.011);

    $this->actingAs($user)->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id, 'symbol' => 'ETHUSDT', 'side' => 'buy', 'stake_amount' => 23,
        'timeframe' => '1m', 'expiry_seconds' => 300, 'mode' => 'live', 'provider' => 'binance',
        'integration_id' => $integration->id, 'indicator_set' => ['MACD'],
    ])->assertCreated();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/fapi/v1/order')
        && $request->method() === 'POST'
        && ($request->data()['quantity'] ?? null) === '0.011');
    expect(QuickTrade::count())->toBe(1);
});

// ── item 2: expiry_seconds cap derived from the provider catalog ─────────

test('a live 4h and 24h expiry is accepted end to end', function (int $expiry) {
    [$user, $workspace, $integration] = n3LiveWorkspace();
    config()->set('quick_trade.block_live_against_decision', true);
    $this->mock(QuickTradeResearchService::class, fn ($mock) => $mock->shouldReceive('capture')->andReturn(n3BuySnapshot()));

    $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id, 'symbol' => 'ETHUSDT', 'side' => 'buy', 'stake_amount' => 23,
        'timeframe' => '1m', 'expiry_seconds' => $expiry, 'mode' => 'live', 'provider' => 'binance',
        'integration_id' => $integration->id, 'indicator_set' => ['MACD'],
    ])->assertCreated();

    expect((int) QuickTrade::findOrFail($response->json('id'))->expiry_seconds)->toBe($expiry);
})->with([14400, 86400]);

test('the forecast endpoint accepts a live 4h and 24h expiry', function (int $expiry) {
    Http::fake();
    n3Forecast(['expiry_seconds' => $expiry, 'durations' => [3600, 14400, 86400]])
        ->assertJsonPath('forecast.horizon_seconds', $expiry);
})->with([14400, 86400]);

test('an expiry above every configured duration is rejected by validation', function () {
    [$user, $workspace, $integration] = n3LiveWorkspace();

    $this->actingAs($user)->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id, 'symbol' => 'ETHUSDT', 'side' => 'buy', 'stake_amount' => 23,
        'timeframe' => '1m', 'expiry_seconds' => 86401, 'mode' => 'live', 'provider' => 'binance',
        'integration_id' => $integration->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['expiry_seconds']);

    $this->postJson('/api/v1/quick-trade/forecast', [
        'candles' => n3Candles(), 'timeframe' => '5m', 'expiry_seconds' => 90000, 'stake_amount' => 50,
    ])->assertStatus(422)->assertJsonValidationErrors(['expiry_seconds']);
    expect(QuickTrade::count())->toBe(0);
});

test('the derived cap is the max of the paper list and every provider catalog', function () {
    expect(app(\App\Domain\Trading\TradingModuleSettingsService::class)->maxExpirySeconds())->toBe(86400);
});

test('a live duration the provider catalog does not offer is still rejected by the service', function () {
    [$user, $workspace, $integration] = n3LiveWorkspace();

    // 7200 passes the coarse validation cap but is not in Binance's list.
    $this->actingAs($user)->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id, 'symbol' => 'ETHUSDT', 'side' => 'buy', 'stake_amount' => 23,
        'timeframe' => '1m', 'expiry_seconds' => 7200, 'mode' => 'live', 'provider' => 'binance',
        'integration_id' => $integration->id,
    ])->assertStatus(422)->assertJsonPath('message', 'Selected duration is not enabled for quick trading.');
});

test('research filters accept a live 4h expiry', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    \App\Models\WorkspaceMember::create(['id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);

    $this->actingAs($user)->getJson("/api/v1/workspaces/{$workspace->id}/quick-trades/research/summary?expiry_seconds=14400")->assertOk();
});
