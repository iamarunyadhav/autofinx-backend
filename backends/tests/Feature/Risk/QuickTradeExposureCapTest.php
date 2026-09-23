<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\QuickTradeService;
use App\Models\Integration;
use App\Models\QuickTrade;
use App\Models\RiskProfile;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Feature-level coverage for the portfolio-wide exposure cap now enforced on
 * live Quick Trade placement (`WorkspaceLiveRiskService::assertQuickTradeExposureAllowed()`,
 * wired into `QuickTradeService::place()`). Reuses the same
 * `max_account_exposure_pct` config value/resolution already used elsewhere
 * in the app (via a `RiskProfile` row), rather than inventing a new config
 * surface for Quick Trade.
 */
function exposureCapWorkspace(?float $maxAccountExposurePct = null): array
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

    if ($maxAccountExposurePct !== null) {
        RiskProfile::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'exposure-capped',
            'is_default' => true,
            'rules' => ['max_account_exposure_pct' => $maxAccountExposurePct],
        ]);
    }

    // Two distinct symbols with live feed snapshots seeded, so a second live
    // trade in the same workspace isn't blocked by the pre-existing
    // duplicate-live-position-per-symbol guard (unrelated to this test) —
    // each test exercises the exposure aggregate ACROSS symbols instead.
    foreach (['ETHUSDT', 'BTCUSDT'] as $seedSymbol) {
        app(MarketPriceCacheService::class)->setLatestPrice(
            'binance_futures',
            $seedSymbol,
            2000.0,
            (int) floor(microtime(true) * 1000),
            ['stream' => strtolower($seedSymbol).'@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
        );
    }

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v2/balance')) {
            // Fixed, real-exchange-reported total balance for every call —
            // this is what WalletAccount.balance (and therefore
            // currentEquity()) syncs to.
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
                'orderId' => (string) random_int(10000, 99999),
                'avgPrice' => '2000.00',
                'executedQty' => '0.050',
            ], 200);
        }

        return Http::response([], 200);
    });

    return [$user, $workspace, $integration];
}

function placeExposureQuickTrade(string $workspaceId, string $integrationId, string $userId, float $stake, string $symbol = 'ETHUSDT'): QuickTrade
{
    return app(QuickTradeService::class)->place([
        'workspace_id' => $workspaceId,
        'symbol' => $symbol,
        'side' => 'buy',
        'stake_amount' => $stake,
        'timeframe' => '1m',
        // 5 candles -- this test's subject is the exposure cap, not the
        // min-horizon-candles rule (which now ships enforced by default).
        'expiry_seconds' => 300,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integrationId,
        'indicator_set' => ['MACD'],
    ], $userId);
}

test('a workspace with configured exposure headroom allows a new live trade, seeding real wallet equity along the way', function () {
    [$user, $workspace, $integration] = exposureCapWorkspace(50.0); // 50% of equity cap

    // First trade: on a brand-new workspace there is no WalletAccount yet,
    // so equity resolves to 0 and the exposure gate is a permissive no-op
    // (the same pre-existing behavior every other Position-based engine's
    // exposure check already has) — this call also seeds the real
    // WalletAccount balance (1000 USDT, from the mocked exchange balance).
    $first = placeExposureQuickTrade($workspace->id, $integration->id, $user->id, 100.0);
    expect($first->status)->toBe('open');

    // Second trade: equity is now a real 1000 from the seeded WalletAccount.
    // Existing exposure (100) + new stake (200) = 300, i.e. 30% of 1000 —
    // within the 50% cap, so this must be allowed.
    $second = placeExposureQuickTrade($workspace->id, $integration->id, $user->id, 200.0, 'BTCUSDT');
    expect($second->status)->toBe('open');

    expect(QuickTrade::where('workspace_id', $workspace->id)->count())->toBe(2);
});

test('a workspace already near its aggregate exposure cap gets a new live trade rejected, correctly summing existing open exposure', function () {
    [$user, $workspace, $integration] = exposureCapWorkspace(15.0); // 15% of equity cap

    // Seed real wallet equity (1000) via a small first trade — well under
    // any reasonable cap on its own.
    $first = placeExposureQuickTrade($workspace->id, $integration->id, $user->id, 100.0);
    expect($first->status)->toBe('open');

    // Existing open exposure is now 100 (10% of 1000). A second trade of 100
    // would bring the aggregate to 200 (20% of 1000) — over the 15% cap.
    // This must be rejected BEFORE any new QuickTrade row / exchange order.
    expect(fn () => placeExposureQuickTrade($workspace->id, $integration->id, $user->id, 100.0, 'BTCUSDT'))
        ->toThrow(RuntimeException::class, 'quick_trade_exceeds_max_account_exposure');

    // Only the first trade exists — the aggregate check used the REAL
    // existing exposure (100), not just the new trade evaluated in
    // isolation (which alone would have been only 10% and allowed).
    expect(QuickTrade::where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('no max_account_exposure_pct configured means the exposure gate is a permissive no-op, same as every other engine', function () {
    [$user, $workspace, $integration] = exposureCapWorkspace(null);

    $first = placeExposureQuickTrade($workspace->id, $integration->id, $user->id, 100.0);
    // 500 stake would be a huge 60% aggregate exposure with a cap configured
    // — but with no cap configured at all, the gate is a no-op regardless.
    // Stays comfortably within the mocked 1000 USDT available margin.
    $second = placeExposureQuickTrade($workspace->id, $integration->id, $user->id, 500.0, 'BTCUSDT');

    expect($first->status)->toBe('open')
        ->and($second->status)->toBe('open');
});
