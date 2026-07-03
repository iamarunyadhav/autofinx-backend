<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Models\Integration;
use App\Models\LedgerTransaction;
use App\Models\QuickTrade;
use App\Models\Setting;
use App\Models\Trade;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

test('live quick trade opens on binance and settles on expiry using timed close', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = liveTradingIntegration($workspace->id);

    $this->actingAs($user);
    seedLiveTradingSettings($workspace->id, $integration->id);

    $orderCounter = 0;
    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$orderCounter) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v2/balance')) {
            return Http::response([
                ['asset' => 'USDT', 'balance' => '1000.00', 'availableBalance' => '1000.00'],
            ], 200);
        }

        if (str_contains($url, '/fapi/v1/ticker/price')) {
            return Http::response(['symbol' => 'ETHUSDT', 'price' => '2000.00'], 200);
        }

        if (str_contains($url, '/fapi/v1/klines')) {
            $rows = [];
            for ($i = 0; $i < 80; $i++) {
                $open = 1800 + ($i * 2);
                $close = $open + 1;
                $rows[] = [
                    1700000000000 + ($i * 60000),
                    (string) $open,
                    (string) ($close + 2),
                    (string) ($open - 2),
                    (string) $close,
                    '100',
                ];
            }

            return Http::response($rows, 200);
        }

        if (str_contains($url, '/fapi/v1/exchangeInfo')) {
            return Http::response([
                'symbols' => [[
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
            $orderCounter++;

            return Http::response([
                'orderId' => (string) (1200 + $orderCounter),
                'avgPrice' => $orderCounter === 1 ? '2000.00' : '2100.00',
                'executedQty' => '0.010',
            ], 200);
        }

        return Http::response([], 200);
    });

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 11:00:00'));
    seedQuickTradeLiveFeed('ETHUSDT', 2000.00);

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

    $quickTrade = QuickTrade::findOrFail($response->json('id'));
    $wallet = WalletAccount::findOrFail($quickTrade->wallet_account_id);

    expect($quickTrade->mode)->toBe('live')
        ->and($quickTrade->provider_key)->toBe('binance')
        ->and((float) $quickTrade->quantity)->toBe(0.01)
        ->and((float) $wallet->balance)->toBe(1000.0)
        ->and((float) $wallet->available)->toBe(980.0)
        ->and((float) $wallet->locked_balance)->toBe(20.0);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 11:01:05'));

    $this->postJson('/api/v1/quick-trade/settle-due', ['limit' => 10])->assertOk();

    $quickTrade->refresh();
    $wallet->refresh();

    expect($quickTrade->status)->toBe('won')
        ->and((float) $quickTrade->profit_loss)->toBe(1.0)
        ->and((float) $quickTrade->payout_amount)->toBe(21.0)
        ->and((float) $wallet->balance)->toBe(1001.0)
        ->and((float) $wallet->available)->toBe(1001.0)
        ->and((float) $wallet->locked_balance)->toBe(0.0)
        ->and(Trade::count())->toBe(2)
        ->and(LedgerTransaction::where('type', 'quick_trade_live_pnl')->count())->toBe(1);

    CarbonImmutable::setTestNow();
});

test('live quick trade rejects binance futures stake below minimum notional before placing order', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = liveTradingIntegration($workspace->id);

    $this->actingAs($user);
    seedLiveTradingSettings($workspace->id, $integration->id);

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
            throw new RuntimeException('Order endpoint should not be called for below-minimum stake.');
        }

        return Http::response([], 200);
    });

    $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'sell',
        'stake_amount' => 10,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Stake too small for ETHUSDT. Minimum required: $20.');
});

test('live quick trade is blocked when required settler runtime is unhealthy', function () {
    config(['autofinx.runtime.require_green_for_live' => true]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = liveTradingIntegration($workspace->id);

    $this->actingAs($user);
    seedLiveTradingSettings($workspace->id, $integration->id);

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
    ])->assertStatus(422)
        ->assertJsonPath('message', 'quick_trade_settler_unhealthy: Quick Trade settler is not running. Timed live trades may not close reliably.');
});

test('live quick trade sell settles with positive pnl when price falls', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = liveTradingIntegration($workspace->id);

    $this->actingAs($user);
    seedLiveTradingSettings($workspace->id, $integration->id);

    $orderCounter = 0;
    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$orderCounter) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v2/balance')) {
            return Http::response([
                ['asset' => 'USDT', 'balance' => '1000.00', 'availableBalance' => '1000.00'],
            ], 200);
        }

        if (str_contains($url, '/fapi/v1/ticker/price')) {
            return Http::response(['symbol' => 'ETHUSDT', 'price' => '2000.00'], 200);
        }

        if (str_contains($url, '/fapi/v1/klines')) {
            return Http::response([], 200);
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
            $orderCounter++;

            return Http::response([
                'orderId' => (string) (2200 + $orderCounter),
                'avgPrice' => $orderCounter === 1 ? '2000.00' : '1900.00',
                'executedQty' => '0.010',
            ], 200);
        }

        return Http::response([], 200);
    });

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 12:00:00'));
    seedQuickTradeLiveFeed('ETHUSDT', 2000.00);

    $response = $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'side' => 'sell',
        'stake_amount' => 20,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
    ])->assertCreated();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-10 12:01:05'));

    $this->postJson('/api/v1/quick-trade/settle-due', ['limit' => 10])->assertOk();

    $quickTrade = QuickTrade::findOrFail($response->json('id'));

    expect($quickTrade->status)->toBe('won')
        ->and((float) $quickTrade->profit_loss)->toBe(1.0)
        ->and((float) $quickTrade->payout_amount)->toBe(21.0);

    CarbonImmutable::setTestNow();
});

test('live quick trade blocks duplicate same symbol open position', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = liveTradingIntegration($workspace->id);

    $this->actingAs($user);
    seedLiveTradingSettings($workspace->id, $integration->id);

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

        if (str_contains($url, '/fapi/v1/klines')) {
            return Http::response([], 200);
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
                'orderId' => '3201',
                'avgPrice' => '2000.00',
                'executedQty' => '0.010',
            ], 200);
        }

        return Http::response([], 200);
    });

    $payload = [
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
    ];

    seedQuickTradeLiveFeed('ETHUSDT', 2000.00);
    $this->postJson('/api/v1/quick-trade/place', $payload)->assertCreated();

    $this->postJson('/api/v1/quick-trade/place', array_merge($payload, ['side' => 'sell']))
        ->assertStatus(422)
        ->assertJsonPath('message', 'duplicate_live_position_detected: Another live position is already open for this symbol.');

    expect(QuickTrade::where('workspace_id', $workspace->id)->where('symbol', 'ETHUSDT')->where('status', 'open')->count())->toBe(1);
});

test('live quick trade rejects stale binance websocket feed before placing order', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = liveTradingIntegration($workspace->id);

    $this->actingAs($user);
    seedLiveTradingSettings($workspace->id, $integration->id);
    clearQuickTradeLiveFeed('SOLUSDT');

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        if (str_contains($request->url(), '/fapi/v1/order') && $request->method() === 'POST') {
            throw new RuntimeException('Order endpoint should not be called with stale live feed.');
        }

        return Http::response([], 200);
    });

    $response = $this->postJson('/api/v1/quick-trade/place', [
        'workspace_id' => $workspace->id,
        'symbol' => 'SOLUSDT',
        'side' => 'buy',
        'stake_amount' => 20,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'mode' => 'live',
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'indicator_set' => ['MACD'],
    ])->assertStatus(422);

    expect((string) $response->json('message'))->toStartWith('live_feed_stale:')
        ->and(QuickTrade::where('workspace_id', $workspace->id)->exists())->toBeFalse();
});

function seedLiveTradingSettings(string $workspaceId, string $integrationId): void
{
    foreach ([
        'trading_mode' => 'live',
        'live_enabled' => true,
        'preferred_provider' => 'binance',
        'preferred_integration_id' => $integrationId,
        'venue_environment' => 'live',
    ] as $key => $value) {
        Setting::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'group' => 'trading',
                'key' => $key,
            ],
            [
                'id' => (string) Str::uuid(),
                'value_json' => $value,
                'encrypted' => false,
            ]
        );
    }
}

function liveTradingIntegration(string $workspaceId): Integration
{
    return Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
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
}

function seedQuickTradeLiveFeed(string $symbol, float $price): void
{
    app(MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures',
        $symbol,
        $price,
        (int) floor(microtime(true) * 1000),
        [
            'stream' => strtolower($symbol).'@trade',
            'event_type' => 'trade',
            'market_type' => 'futures',
        ],
    );
}

function clearQuickTradeLiveFeed(string $symbol): void
{
    try {
        Redis::del('live-feed:binance_futures:'.strtoupper($symbol));
    } catch (Throwable) {
    }
}
