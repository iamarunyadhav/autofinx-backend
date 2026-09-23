<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Models\Integration;
use App\Models\QuickTrade;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Feature-level coverage for:
 *  - the minimum-margin pre-check (`BinanceTimedFuturesProvider::assertSufficientMargin()`,
 *    `MARGIN_SAFETY_BUFFER_PCT`), which applies regardless of leverage;
 *  - the pre-trade liquidation-distance check (`LeverageValidationService::
 *    estimateLiquidationDistance()`, `MIN_LIQUIDATION_DISTANCE_PCT`), which
 *    only applies to leveraged trades.
 */
function marginTestWorkspace(string $availableBalance, array $brackets = []): array
{
    // Part A5 (2026-09-24): these tests predate the fee-aware live execution
    // guard and use a live placement purely as the vehicle for testing
    // something else (stop-loss, take-profit, margin/liquidation, settlement).
    // With `quick_trade.block_live_against_decision` at its real default of
    // true, the genuine forecast for these synthetic candle series returns
    // WAIT and would refuse the order before the behaviour under test is ever
    // reached. Opting out here keeps each test focused on its own subject;
    // the guard itself has dedicated coverage in
    // QuickTradeDecisionExecutionGuardTest.
    config()->set('quick_trade.block_live_against_decision', false);
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

    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($availableBalance, $brackets) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v2/balance')) {
            return Http::response([
                ['asset' => 'USDT', 'balance' => $availableBalance, 'availableBalance' => $availableBalance],
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
        if (str_contains($url, '/fapi/v1/leverageBracket')) {
            return Http::response(['symbol' => 'ETHUSDT', 'brackets' => $brackets], 200);
        }
        if (str_contains($url, '/fapi/v1/leverage') && $request->method() === 'POST') {
            return Http::response(['leverage' => 10, 'symbol' => 'ETHUSDT'], 200);
        }
        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            return Http::response([
                'orderId' => '9001',
                'avgPrice' => '2000.00',
                'executedQty' => '0.100',
            ], 200);
        }

        return Http::response([], 200);
    });

    return [$user, $workspace, $integration];
}

function placeMarginQuickTrade(Workspace $workspace, Integration $integration, array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/quick-trade/place', array_merge([
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'stake_amount' => 40,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
    ], $extra));
}

test('a trade whose required margin (with safety buffer) exceeds available balance is rejected before any Binance order call', function () {
    // Available balance 50 USDT. Stake 48 clears the pre-existing raw
    // "stake <= available" wallet-reservation check (48 <= 50) but the NEW
    // margin-safety-buffer check (48 * 1.10 = 52.80) does not.
    [$user, $workspace, $integration] = marginTestWorkspace('50.00');
    $this->actingAs($user);

    $response = placeMarginQuickTrade($workspace, $integration, ['stake_amount' => 48])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('insufficient_margin');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/order') && $request->method() === 'POST');
    expect(QuickTrade::count())->toBe(0);
});

test('a trade with sufficient margin (including the safety buffer) proceeds normally', function () {
    [$user, $workspace, $integration] = marginTestWorkspace('50.00');
    $this->actingAs($user);

    // Stake 40: required margin 44 <= 50 available.
    $response = placeMarginQuickTrade($workspace, $integration, ['stake_amount' => 40])
        ->assertCreated();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/fapi/v1/order') && $request->method() === 'POST');
    expect(QuickTrade::count())->toBe(1);
    expect($response->json('status'))->toBe('open');
});

test('the margin pre-check applies even when leverage is off (non-leveraged live trades are not exempt)', function () {
    [$user, $workspace, $integration] = marginTestWorkspace('50.00');
    $this->actingAs($user);

    // No leverage fields at all — still a live trade, still gated.
    $response = placeMarginQuickTrade($workspace, $integration, ['stake_amount' => 48])
        ->assertStatus(422);

    expect($response->json('message'))->toContain('insufficient_margin');
});

test('a leveraged trade whose estimated liquidation distance falls below the safety floor is rejected with the real estimated numbers', function () {
    [$user, $workspace, $integration] = marginTestWorkspace('1000.00', [
        // 40x leverage against a 1% maintenance margin rate: estimated
        // distance = 1/40 - 0.01 = 1.5%, below the 3% MIN_LIQUIDATION_DISTANCE_PCT floor.
        ['bracket' => 1, 'initialLeverage' => 50, 'notionalCap' => 50000, 'notionalFloor' => 0, 'maintMarginRatio' => 0.01],
    ]);
    $this->actingAs($user);

    $response = placeMarginQuickTrade($workspace, $integration, [
        'stake_amount' => 40,
        'leverage_enabled' => true,
        'leverage' => 40,
        // Leverage 40 > HIGH_LEVERAGE_STOP_LOSS_THRESHOLD (20) requires a
        // stop-loss to clear that earlier, unrelated gate first.
        'stop_loss_pct' => -3.0,
    ])->assertStatus(422);

    expect($response->json('message'))
        ->toContain('estimated_liquidation_distance_pct')
        ->toContain('1.5');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/order') && $request->method() === 'POST');
    expect(QuickTrade::count())->toBe(0);
});

test('a leveraged trade with a safe estimated liquidation distance proceeds normally', function () {
    [$user, $workspace, $integration] = marginTestWorkspace('1000.00', [
        // 10x leverage against a 0.5% maintenance margin rate: estimated
        // distance = 1/10 - 0.005 = 9.5%, comfortably above the 3% floor.
        ['bracket' => 1, 'initialLeverage' => 20, 'notionalCap' => 50000, 'notionalFloor' => 0, 'maintMarginRatio' => 0.005],
    ]);
    $this->actingAs($user);

    $response = placeMarginQuickTrade($workspace, $integration, [
        'stake_amount' => 40,
        'leverage_enabled' => true,
        'leverage' => 10,
    ])->assertCreated();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');
    expect($response->json('status'))->toBe('open');
});

test('a leveraged trade is rejected when the maintenance margin rate cannot be determined (fails closed, never assumes safe)', function () {
    [$user, $workspace, $integration] = marginTestWorkspace('1000.00', [
        // No maintMarginRatio in this bracket at all.
        ['bracket' => 1, 'initialLeverage' => 20, 'notionalCap' => 50000, 'notionalFloor' => 0],
    ]);
    $this->actingAs($user);

    $response = placeMarginQuickTrade($workspace, $integration, [
        'stake_amount' => 40,
        'leverage_enabled' => true,
        'leverage' => 10,
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('unable_to_verify_liquidation_distance');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');
});
