<?php

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
