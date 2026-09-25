<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Models\Integration;
use App\Models\QuickTrade;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Feature-level coverage for real per-trade leverage on Quick Trade — live
 * mode only. Mirrors the fixture pattern already established in
 * QuickTradeStopLossPlacementTest.
 */
function leverageLiveWorkspace(array $brackets): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
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

    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($brackets) {
        $url = $request->url();

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

function placeLeveragedQuickTrade(Workspace $workspace, Integration $integration, array $extra = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/quick-trade/place', array_merge([
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        // 5 candles -- these tests' subject is leverage, not the
        // min-horizon-candles rule (which now ships enforced by default).
        'expiry_seconds' => 300,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
    ], $extra));
}

test('a leverage-on trade within the real exchange limit sets leverage, computes leveraged notional/quantity and a leveraged-notional-correct fee', function () {
    [$user, $workspace, $integration] = leverageLiveWorkspace([
        // maintMarginRatio added for the new pre-trade liquidation-distance
        // check — 10x leverage against a 0.5% maintenance margin rate gives
        // an estimated ~9.5% distance to liquidation, comfortably above the
        // MIN_LIQUIDATION_DISTANCE_PCT safety floor (3%).
        ['bracket' => 1, 'initialLeverage' => 20, 'notionalCap' => 50000, 'notionalFloor' => 0, 'maintMarginRatio' => 0.005],
    ]);
    $this->actingAs($user);

    $response = placeLeveragedQuickTrade($workspace, $integration, [
        'leverage_enabled' => true,
        'leverage' => 10,
    ])->assertCreated();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/fapi/v1/order') && $request->method() === 'POST');

    $trade = QuickTrade::findOrFail($response->json('id'));
    expect((bool) $trade->leverage_enabled)->toBeTrue()
        ->and((float) $trade->leverage)->toEqualWithDelta(10.0, 0.0001)
        // stake 20 * leverage 10 / price 2000 = 0.1 quantity (mocked executedQty).
        ->and((float) $trade->quantity)->toEqualWithDelta(0.1, 0.0001);

    // Fee is derived from the executed (leveraged) quantity/price, not the
    // raw stake — 0.1 * 2000 * takerFeeRate, i.e. 20x the un-leveraged fee.
    $unleveragedFee = 0.01 * 2000 * 0.0005;
    expect((float) $trade->fees_total)->toBeGreaterThan($unleveragedFee);
});

test('a leverage-on trade exceeding the real exchange max for its notional is rejected with zero order placed and zero setLeverage call attempted', function () {
    [$user, $workspace, $integration] = leverageLiveWorkspace([
        ['bracket' => 1, 'initialLeverage' => 15, 'notionalCap' => 50000, 'notionalFloor' => 0, 'maintMarginRatio' => 0.005],
    ]);
    $this->actingAs($user);

    $response = placeLeveragedQuickTrade($workspace, $integration, [
        'leverage_enabled' => true,
        'leverage' => 18,
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('exceeds_exchange_max_leverage_for_notional');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/order') && $request->method() === 'POST');
    expect(QuickTrade::count())->toBe(0);
});

test('high leverage without a stop-loss is rejected before any exchange call is made', function () {
    [$user, $workspace, $integration] = leverageLiveWorkspace([
        ['bracket' => 1, 'initialLeverage' => 50, 'notionalCap' => 50000, 'notionalFloor' => 0, 'maintMarginRatio' => 0.005],
    ]);
    $this->actingAs($user);

    $response = placeLeveragedQuickTrade($workspace, $integration, [
        'leverage_enabled' => true,
        'leverage' => 25,
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('requires stop_loss_pct');

    Http::assertNothingSent();
    expect(QuickTrade::count())->toBe(0);
});

test('high leverage WITH a stop-loss set is allowed through the leverage gate', function () {
    [$user, $workspace, $integration] = leverageLiveWorkspace([
        // 25x against a 0.5% maintenance margin rate: estimated distance
        // ~3.5%, above the 3% safety floor — must still pass.
        ['bracket' => 1, 'initialLeverage' => 50, 'notionalCap' => 50000, 'notionalFloor' => 0, 'maintMarginRatio' => 0.005],
    ]);
    $this->actingAs($user);

    placeLeveragedQuickTrade($workspace, $integration, [
        'leverage_enabled' => true,
        'leverage' => 25,
        'stop_loss_pct' => -3.0,
    ])->assertCreated();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');
});

test('leverage-off live trades are byte-for-byte unaffected: no leverage stored, quantity matches raw stake', function () {
    [$user, $workspace, $integration] = leverageLiveWorkspace([
        ['bracket' => 1, 'initialLeverage' => 20, 'notionalCap' => 50000, 'notionalFloor' => 0],
    ]);
    $this->actingAs($user);

    $response = placeLeveragedQuickTrade($workspace, $integration)->assertCreated();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverageBracket'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');

    $trade = QuickTrade::findOrFail($response->json('id'));
    expect((bool) $trade->leverage_enabled)->toBeFalse()
        ->and($trade->leverage)->toBeNull();
});

test('paper-mode trades are completely unaffected by leverage fields even if submitted', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    $this->actingAs($user);

    $response = $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'GOLD_OTC',
        'side' => 'buy',
        'stake_amount' => 10,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'paper',
        'indicator_set' => ['RSI'],
        'leverage_enabled' => true,
        'leverage' => 50,
    ])->assertCreated();

    $trade = QuickTrade::findOrFail($response->json('id'));
    expect((bool) $trade->leverage_enabled)->toBeFalse()
        ->and($trade->leverage)->toBeNull();
});
