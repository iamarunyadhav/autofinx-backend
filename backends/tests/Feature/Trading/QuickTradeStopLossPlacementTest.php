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
 * Feature-level coverage for the optional per-trade `stop_loss_pct` field on
 * QuickTrade placement — live mode stores it, paper mode never does.
 */
function stopLossPlacementLiveWorkspace(): array
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

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
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
        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            return Http::response([
                'orderId' => '9001',
                'avgPrice' => '2000.00',
                'executedQty' => '0.010',
            ], 200);
        }

        return Http::response([], 200);
    });

    return [$user, $workspace, $integration];
}

test('placing a live quick trade with stop_loss_pct set stores it correctly', function () {
    [$user, $workspace, $integration] = stopLossPlacementLiveWorkspace();
    $this->actingAs($user);

    $response = $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
        'stop_loss_pct' => -2.5,
    ])->assertCreated();

    $trade = QuickTrade::findOrFail($response->json('id'));
    expect((float) $trade->stop_loss_pct)->toEqualWithDelta(-2.5, 0.0001);
    expect($trade->stop_loss_triggered_at)->toBeNull();
});

test('placing a live quick trade without stop_loss_pct defaults it to null (feature off)', function () {
    [$user, $workspace, $integration] = stopLossPlacementLiveWorkspace();
    $this->actingAs($user);

    $response = $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
    ])->assertCreated();

    $trade = QuickTrade::findOrFail($response->json('id'));
    expect($trade->stop_loss_pct)->toBeNull();
});

test('a positive stop_loss_pct is rejected as invalid', function () {
    [$user, $workspace, $integration] = stopLossPlacementLiveWorkspace();
    $this->actingAs($user);

    $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
        'stop_loss_pct' => 2.5,
    ])->assertStatus(422);
});

test('paper-mode quick trades never store a stop_loss_pct even if one is submitted', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $this->actingAs($user);

    // The controller's validator rejects a non-negative value outright, and
    // since paper mode has no real position to protect this field is only
    // ever meaningful for live mode — QuickTradeService::place() silently
    // ignores it for paper trades (documented choice: inert, not rejected),
    // so submitting a valid negative value on a paper trade still results in
    // a null column, feature off.
    $response = $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'GOLD_OTC',
        'side' => 'buy',
        'stake_amount' => 10,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'paper',
        'indicator_set' => ['RSI'],
        'stop_loss_pct' => -2.5,
    ])->assertCreated();

    $trade = QuickTrade::findOrFail($response->json('id'));
    expect($trade->stop_loss_pct)->toBeNull();
});
