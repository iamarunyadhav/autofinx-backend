<?php

use App\Models\FutureTradeBotConfig;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function futureTradeLiveSettings(array $overrides = []): array
{
    return array_merge(config('future_trade.defaults'), [
        'liveTrading' => true,
        'paperTrading' => false,
        'forceTestnet' => true,
        'envMode' => 'testnet',
        'envTestnetEnabled' => true,
        'testnetApiUrl' => 'https://testnet.binancefuture.local',
        'testnetApiKey' => 'test-key',
        'testnetApiSecret' => 'test-secret',
        'symbols' => ['ETHUSDT', 'BTCUSDT'],
        'tradeAmountUsdt' => 25,
    ], $overrides);
}

function createFutureTradeLiveConfig(string $workspaceId, array $state = [], array $settings = []): FutureTradeBotConfig
{
    $config = FutureTradeBotConfig::create([
        'workspace_id' => $workspaceId,
        'bot_name' => 'Future Trade Bot',
        'paper_trading' => false,
        'enable_live_trading' => true,
        'enabled' => true,
        'settings' => futureTradeLiveSettings($settings),
    ]);

    $config->state()->create(array_merge([
        'status' => 'Running',
        'state' => 'LIVE_IN_POSITION',
        'connection_status' => 'Connected',
        'current_symbol' => 'ETHUSDT',
        'current_signal' => 'SELL_LIVE_EXECUTED',
        'position_side' => 'SHORT',
        'entry_price' => 2283.93,
        'quantity' => 0.017,
        'current_price' => 2282.0,
        'confidence' => 0.61,
        'trading_day' => now()->toDateString(),
    ], $state));

    return $config->fresh('state');
}

test('future trade auto recovery clears stale local position and cancels reduce-only orders', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $config = createFutureTradeLiveConfig($workspace->id);
    $cancelled = 0;

    Http::fake(function ($request) use (&$cancelled) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v2/positionRisk')) {
            return Http::response([[
                'symbol' => 'ETHUSDT',
                'positionAmt' => '0',
                'entryPrice' => '0',
                'markPrice' => '2282.00',
                'unRealizedProfit' => '0',
            ]], 200);
        }

        if (str_contains($url, '/fapi/v1/openOrders')) {
            return Http::response([[
                'symbol' => 'ETHUSDT',
                'orderId' => '777',
                'clientOrderId' => 'ft-close-stale',
                'reduceOnly' => true,
            ]], 200);
        }

        if ($request->method() === 'DELETE' && str_contains($url, '/fapi/v1/order')) {
            $cancelled++;
            return Http::response(['status' => 'CANCELED'], 200);
        }

        return Http::response([], 200);
    });

    $this->actingAs($user)
        ->postJson("/api/v1/workspaces/{$workspace->id}/future-trade/recover", ['action' => 'auto'])
        ->assertOk()
        ->assertJsonPath('resolved', true)
        ->assertJsonPath('local_state_cleared', true)
        ->assertJsonPath('cancelled_reduce_only_orders', 1);

    expect($cancelled)->toBe(1);

    $state = $config->fresh('state')->state;
    expect((float) $state->quantity)->toBe(0.0)
        ->and($state->position_side)->toBeNull()
        ->and($state->current_signal)->toBe('WAITING_FOR_SIGNAL');
});

test('future trade sync state adopts actual Binance open position', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $config = createFutureTradeLiveConfig($workspace->id, [
        'quantity' => 0.017,
        'position_side' => 'SHORT',
    ]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v2/positionRisk')) {
            return Http::response([[
                'symbol' => 'ETHUSDT',
                'positionAmt' => '-0.011',
                'entryPrice' => '2283.50',
                'markPrice' => '2281.50',
                'unRealizedProfit' => '0.022',
            ]], 200);
        }

        if (str_contains($url, '/fapi/v1/openOrders')) {
            return Http::response([], 200);
        }

        return Http::response([], 200);
    });

    $this->actingAs($user)
        ->postJson("/api/v1/workspaces/{$workspace->id}/future-trade/recover", ['action' => 'sync_state'])
        ->assertOk()
        ->assertJsonPath('resolved', true)
        ->assertJsonPath('position_still_open', true)
        ->assertJsonPath('exchange_quantity', 0.011);

    $state = $config->fresh('state')->state;
    expect((float) $state->quantity)->toBe(0.011)
        ->and($state->position_side)->toBe('SHORT')
        ->and((float) $state->entry_price)->toBe(2283.5);
});

test('future trade scan command refreshes scanner snapshot and graph from fake Binance data', function () {
    $workspace = Workspace::factory()->create();
    $config = createFutureTradeLiveConfig($workspace->id, [
        'state' => 'SCANNING',
        'quantity' => 0,
        'position_side' => null,
        'entry_price' => null,
    ], [
        'liveTrading' => false,
        'paperTrading' => true,
        'forceTestnet' => true,
        'symbols' => ['ETHUSDT', 'BTCUSDT'],
        'minConfidence' => 0.5,
        'autoExecutePaperSignals' => false,
    ]);
    $config->update(['paper_trading' => true, 'enable_live_trading' => false]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v1/ticker/24hr')) {
            return Http::response([
                [
                    'symbol' => 'ETHUSDT',
                    'lastPrice' => '2285.00',
                    'priceChangePercent' => '-1.90',
                    'quoteVolume' => '500000000',
                    'highPrice' => '2350.00',
                    'lowPrice' => '2260.00',
                    'weightedAvgPrice' => '2295.00',
                    'openPrice' => '2330.00',
                ],
                [
                    'symbol' => 'BTCUSDT',
                    'lastPrice' => '79000.00',
                    'priceChangePercent' => '-1.20',
                    'quoteVolume' => '900000000',
                    'highPrice' => '80500.00',
                    'lowPrice' => '78500.00',
                    'weightedAvgPrice' => '79400.00',
                    'openPrice' => '80000.00',
                ],
            ], 200);
        }

        if (str_contains($url, '/fapi/v1/klines')) {
            $base = str_contains($url, 'BTCUSDT') ? 80000 : 2300;
            $rows = [];
            for ($i = 0; $i < 12; $i++) {
                $open = $base - $i;
                $close = $open - 0.5;
                $rows[] = [1700000000000 + ($i * 60000), (string) $open, (string) ($open + 1), (string) ($close - 1), (string) $close, '100'];
            }

            return Http::response($rows, 200);
        }

        return Http::response([], 200);
    });

    $this->artisan('future-trade:scan-all')->assertExitCode(0);

    $state = $config->fresh('state')->state;
    expect($state->scanner_snapshot)->not->toBeEmpty()
        ->and($state->chart_points)->not->toBeEmpty()
        ->and($state->last_scan_at)->not->toBeNull()
        ->and($state->connection_status)->toBe('Connected');
});

test('future trade scheduler command is registered without long-running loop option', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('future-trade:scan-all')
        ->doesntExpectOutputToContain('future-trade:scan-all --loop')
        ->assertExitCode(0);
});
