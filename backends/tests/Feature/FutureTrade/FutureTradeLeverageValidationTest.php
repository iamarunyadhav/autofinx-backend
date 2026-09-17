<?php

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\FutureTradeBotConfig;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Real-money leverage hardening for Future Trade's live entry path
 * (openLivePositionLocked). Proves the reject-not-clamp behavior change:
 * before this session, `$leverage = min(settings.leverage, settings.maxLeverage)`
 * was trusted blindly with no check against Binance's real, notional-tiered
 * leverage brackets. Now a requested leverage that the real exchange
 * wouldn't actually support for this position's notional is BLOCKED, never
 * silently traded at a reduced value.
 */
function futureTradeLiveLeverageConfig(string $workspaceId, array $settings = []): FutureTradeBotConfig
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
            'liveTrading' => true,
            'paperTrading' => false,
            'forceTestnet' => true,
            'envTestnetEnabled' => true,
            'testnetApiUrl' => 'https://testnet.binancefuture.local',
            'testnetApiKey' => 'test-key',
            'testnetApiSecret' => 'test-secret',
            'allowLiveWithoutUserStream' => true,
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

function fakeFutureTradeBinanceForLeverage(array $brackets): void
{
    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($brackets) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v2/positionRisk')) {
            return Http::response([], 200);
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
            return Http::response(['symbol' => 'ETHUSDT', 'brackets' => $brackets], 200);
        }
        if (str_contains($url, '/fapi/v1/leverage') && $request->method() === 'POST') {
            return Http::response(['leverage' => 5, 'symbol' => 'ETHUSDT'], 200);
        }
        if (str_contains($url, '/fapi/v1/marginType') && $request->method() === 'POST') {
            return Http::response(['msg' => 'success'], 200);
        }
        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            return Http::response([
                'orderId' => '778899',
                'avgPrice' => '2000.00',
                'executedQty' => '0.020',
                'status' => 'FILLED',
            ], 200);
        }

        return Http::response([], 200);
    });
}

function invokeOpenLivePositionLocked(FutureTradeBotConfig $config, array $signal)
{
    $service = app(FutureTradeBotService::class);
    $method = new ReflectionMethod(FutureTradeBotService::class, 'openLivePositionLocked');
    $method->setAccessible(true);

    return $method->invoke($service, $config->fresh('state'), $signal);
}

test('a config requesting more than the real exchange max for its notional is BLOCKED, not silently traded at a reduced leverage', function () {
    $workspace = Workspace::factory()->create();
    // Requested leverage (10x, well within the local leverage<=maxLeverage
    // policy clamp) exceeds the REAL exchange tier max (5x) for this
    // position's actual notional — must be rejected outright.
    $config = futureTradeLiveLeverageConfig($workspace->id, [
        'leverage' => 10,
        'maxLeverage' => 10,
    ]);

    fakeFutureTradeBinanceForLeverage([
        ['bracket' => 1, 'initialLeverage' => 5, 'notionalCap' => 50000, 'notionalFloor' => 0],
    ]);

    $signal = [
        'symbol' => 'ETHUSDT',
        'signal' => 'BUY',
        'price' => 2000.0,
        'confidence' => 0.82,
        'signal_key' => 'ethusdt-buy-test',
    ];

    $result = invokeOpenLivePositionLocked($config, $signal);

    expect($result)->toBe('leverage_exceeds_exchange_max');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/order') && $request->method() === 'POST');

    $log = $config->logs()->latest('occurred_at')->first();
    expect($log?->event)->toBe('TRADE_BLOCKED')
        ->and($log?->context['hardBlockers'] ?? [])->toContain('leverage_exceeds_exchange_max');
});

test('a config requesting leverage within the real exchange max for its notional proceeds to set leverage and place the order', function () {
    $workspace = Workspace::factory()->create();
    $config = futureTradeLiveLeverageConfig($workspace->id, [
        'leverage' => 5,
        'maxLeverage' => 5,
    ]);

    fakeFutureTradeBinanceForLeverage([
        ['bracket' => 1, 'initialLeverage' => 20, 'notionalCap' => 50000, 'notionalFloor' => 0],
    ]);

    $signal = [
        'symbol' => 'ETHUSDT',
        'signal' => 'BUY',
        'price' => 2000.0,
        'confidence' => 0.82,
        'signal_key' => 'ethusdt-buy-ok',
    ];

    $result = invokeOpenLivePositionLocked($config, $signal);

    expect($result)->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/fapi/v1/order') && $request->method() === 'POST');
});

test('high leverage without a configured stop-loss is blocked', function () {
    $workspace = Workspace::factory()->create();
    $config = futureTradeLiveLeverageConfig($workspace->id, [
        'leverage' => 25,
        'maxLeverage' => 25,
        'stopLossPercent' => 0,
        'useAtrRiskTargets' => false,
    ]);

    fakeFutureTradeBinanceForLeverage([
        ['bracket' => 1, 'initialLeverage' => 50, 'notionalCap' => 50000, 'notionalFloor' => 0],
    ]);

    $signal = [
        'symbol' => 'ETHUSDT',
        'signal' => 'BUY',
        'price' => 2000.0,
        'confidence' => 0.82,
        'signal_key' => 'ethusdt-buy-highlev',
    ];

    $result = invokeOpenLivePositionLocked($config, $signal);

    expect($result)->toBe('high_leverage_requires_stop_loss');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/order') && $request->method() === 'POST');
});
