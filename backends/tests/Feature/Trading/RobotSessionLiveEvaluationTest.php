<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\RobotSessionService;
use App\Models\Integration;
use App\Models\MarketFeedStatus;
use App\Models\QuickTrade;
use App\Models\RobotSession;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

test('robot session evaluates in live mode and opens a live quick trade', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = robotLiveIntegration($workspace->id);

    robotSeedLiveTradingSettings($workspace->id, $integration->id);

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

        if (str_contains($url, '/fapi/v1/klines')) {
            $rows = [];
            for ($i = 0; $i < 100; $i++) {
                $open = 1701 + ($i * 3);
                $close = $open + 2;
                $rows[] = [
                    1700000000000 + ($i * 60000),
                    (string) $open,
                    (string) ($close + 20),
                    (string) ($open - 20),
                    (string) $close,
                    '100',
                ];
            }

            return Http::response($rows, 200);
        }

        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            return Http::response([
                'orderId' => '7771',
                'avgPrice' => '2000.00',
                'executedQty' => '0.010',
            ], 200);
        }

        return Http::response([], 200);
    });

    $session = app(RobotSessionService::class)->create($workspace->id, [
        'name' => 'ETH live robot',
        'symbol' => 'ETHUSDT',
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'indicator_set' => ['MACD'],
        'strategy_profile' => 'balanced',
        'base_stake' => 20,
        'confidence_threshold' => 50,
        'provider' => 'binance',
        'integration_id' => $integration->id,
    ], $user->id);

    $session = app(RobotSessionService::class)->start($session);
    seedRobotFeedStatus($workspace->id, 'ETHUSDT', 2000.00);
    seedRobotLiveFeed('ETHUSDT', 2000.00);

    app(RobotSessionService::class)->evaluateSession($session);
    $result = app(RobotSessionService::class)->evaluateSession($session->fresh());

    $trade = QuickTrade::where('robot_session_id', $session->id)->first();

    expect($result['status'])->toBe('placed')
        ->and($trade)->not->toBeNull()
        ->and($trade->mode)->toBe('live')
        ->and($trade->provider_key)->toBe('binance')
        ->and((float) $trade->quantity)->toBe(0.01)
        ->and(RobotSession::findOrFail($session->id)->mode)->toBe('live');
});

test('robot session evaluation is locked against concurrent duplicate execution', function () {
    // Reproduces the production condition where the Supervisor
    // autofinx-robot-evaluator daemon and Laravel's scheduler (schedule:work +
    // schedule:run both active) can call evaluateSession() for the same
    // session at nearly the same instant. Without a per-session lock, both
    // callers could pass the cooldown/active-trade checks and each place a
    // live order for one signal.
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = robotLiveIntegration($workspace->id);

    robotSeedLiveTradingSettings($workspace->id, $integration->id);

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

        if (str_contains($url, '/fapi/v1/klines')) {
            $rows = [];
            for ($i = 0; $i < 100; $i++) {
                $open = 1701 + ($i * 3);
                $close = $open + 2;
                $rows[] = [
                    1700000000000 + ($i * 60000),
                    (string) $open,
                    (string) ($close + 20),
                    (string) ($open - 20),
                    (string) $close,
                    '100',
                ];
            }

            return Http::response($rows, 200);
        }

        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            return Http::response([
                'orderId' => '7771',
                'avgPrice' => '2000.00',
                'executedQty' => '0.010',
            ], 200);
        }

        return Http::response([], 200);
    });

    $session = app(RobotSessionService::class)->create($workspace->id, [
        'name' => 'ETH live robot',
        'symbol' => 'ETHUSDT',
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'indicator_set' => ['MACD'],
        'strategy_profile' => 'balanced',
        'base_stake' => 20,
        'confidence_threshold' => 50,
        'provider' => 'binance',
        'integration_id' => $integration->id,
    ], $user->id);

    $session = app(RobotSessionService::class)->start($session);
    seedRobotFeedStatus($workspace->id, 'ETHUSDT', 2000.00);
    seedRobotLiveFeed('ETHUSDT', 2000.00);

    // Get the session past the signal-confirmation streak so it would place a
    // trade on the very next call if nothing else blocked it.
    app(RobotSessionService::class)->evaluateSession($session);

    // Simulate a second, concurrent evaluator already holding the per-session
    // lock (e.g. the Supervisor daemon mid-evaluation) when this call arrives.
    $lock = \Illuminate\Support\Facades\Cache::lock('robot-session-eval:' . $session->id, 30);
    expect($lock->get())->toBeTrue();

    $result = app(RobotSessionService::class)->evaluateSession($session->fresh());

    expect($result['status'])->toBe('locked')
        ->and($result['action'])->toBe('skipped')
        ->and(QuickTrade::where('robot_session_id', $session->id)->count())->toBe(0);

    $lock->release();

    // Once the lock is free, evaluation proceeds normally and places the trade
    // — proving the lock blocks concurrent duplicates without wedging the bot.
    $result = app(RobotSessionService::class)->evaluateSession($session->fresh());

    expect($result['status'])->toBe('placed')
        ->and(QuickTrade::where('robot_session_id', $session->id)->count())->toBe(1);
});

test('robot session blocks live entry when expected move cannot beat fees and slippage', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = robotLiveIntegration($workspace->id);

    robotSeedLiveTradingSettings($workspace->id, $integration->id);

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
            $rows = [];
            for ($i = 0; $i < 100; $i++) {
                $open = 1950 + ($i * 0.2);
                $close = $open + 0.1;
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

        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            throw new RuntimeException('Order endpoint should not be called for low-edge robot entry.');
        }

        return Http::response([], 200);
    });

    $session = app(RobotSessionService::class)->create($workspace->id, [
        'name' => 'ETH live robot',
        'symbol' => 'ETHUSDT',
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'indicator_set' => ['MACD'],
        'strategy_profile' => 'balanced',
        'base_stake' => 50,
        'confidence_threshold' => 50,
        'provider' => 'binance',
        'integration_id' => $integration->id,
    ], $user->id);

    $session = app(RobotSessionService::class)->start($session);
    seedRobotFeedStatus($workspace->id, 'ETHUSDT', 2000.00);
    seedRobotLiveFeed('ETHUSDT', 2000.00);

    app(RobotSessionService::class)->evaluateSession($session);
    $result = app(RobotSessionService::class)->evaluateSession($session->fresh());

    expect($result['status'])->toBe('net_edge_block')
        ->and($result['edge']['tradeable'])->toBeFalse()
        ->and(QuickTrade::where('robot_session_id', $session->id)->exists())->toBeFalse();
});

test('robot session blocks live entry when server websocket feed is stale', function () {
    // Do not use ETHUSDT here: the local market websocket worker can honestly
    // report a fresh ETHUSDT heartbeat while this test is asserting the
    // no-fresh-server-feed path.  This symbol is intentionally absent from
    // that worker's subscription list.
    $symbol = 'ROBOTSTALEUSDT';
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = robotLiveIntegration($workspace->id);

    robotSeedLiveTradingSettings($workspace->id, $integration->id);

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v1/ticker/price')) {
            return Http::response(['symbol' => 'ETHUSDT', 'price' => '2000.00'], 200);
        }

        if (str_contains($url, '/fapi/v1/klines')) {
            $rows = [];
            for ($i = 0; $i < 100; $i++) {
                $open = 1701 + ($i * 3);
                $close = $open + 2;
                $rows[] = [
                    1700000000000 + ($i * 60000),
                    (string) $open,
                    (string) ($close + 20),
                    (string) ($open - 20),
                    (string) $close,
                    '100',
                ];
            }

            return Http::response($rows, 200);
        }

        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            throw new RuntimeException('Order endpoint should not be called with stale server feed.');
        }

        return Http::response([], 200);
    });

    $session = app(RobotSessionService::class)->create($workspace->id, [
        'name' => 'ETH live robot',
        'symbol' => $symbol,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'indicator_set' => ['MACD'],
        'strategy_profile' => 'balanced',
        'base_stake' => 20,
        'confidence_threshold' => 50,
        'provider' => 'binance',
        'integration_id' => $integration->id,
    ], $user->id);

    $session = app(RobotSessionService::class)->start($session);
    clearRobotLiveFeed($symbol);
    seedRobotFeedStatus($workspace->id, $symbol, 2000.00, now()->subSeconds(60));

    app(RobotSessionService::class)->evaluateSession($session);
    $result = app(RobotSessionService::class)->evaluateSession($session->fresh());

    expect($result['status'])->toBe('stale_feed_block')
        ->and(QuickTrade::where('robot_session_id', $session->id)->exists())->toBeFalse();
});

test('robot live start is blocked when required evaluator runtime is unhealthy', function () {
    config(['autofinx.runtime.require_green_for_live' => true]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = robotLiveIntegration($workspace->id);

    robotSeedLiveTradingSettings($workspace->id, $integration->id);

    $session = app(RobotSessionService::class)->create($workspace->id, [
        'name' => 'ETH live robot',
        'symbol' => 'ETHUSDT',
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'indicator_set' => ['MACD'],
        'strategy_profile' => 'balanced',
        'base_stake' => 20,
        'confidence_threshold' => 50,
        'provider' => 'binance',
        'integration_id' => $integration->id,
    ], $user->id);

    expect(fn () => app(RobotSessionService::class)->start($session))
        ->toThrow(RuntimeException::class, 'robot_evaluator_unhealthy');
});

function robotSeedLiveTradingSettings(string $workspaceId, string $integrationId): void
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

function robotLiveIntegration(string $workspaceId): Integration
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
            'api_key' => 'robot-key',
            'api_secret' => 'robot-secret',
        ])),
    ]);
}

function seedRobotFeedStatus(string $workspaceId, string $symbol, float $price, $lastTickAt = null): void
{
    MarketFeedStatus::updateOrCreate(
        [
            'workspace_id' => $workspaceId,
            'symbol' => strtoupper($symbol),
            'exchange' => 'binance',
            'market_type' => 'futures',
        ],
        [
            'id' => (string) Str::uuid(),
            'symbol_id' => (string) Str::uuid(),
            'last_tick_at' => $lastTickAt ?: now(),
            'last_price' => $price,
            'source' => 'websocket',
            'status' => 'healthy',
            'meta_json' => ['test' => true],
        ]
    );
}

function seedRobotLiveFeed(string $symbol, float $price): void
{
    // RefreshDatabase does not flush Redis.  The live-candle cache is global
    // per exchange/symbol/timeframe, so remove an earlier test/worker context
    // before the first evaluation seeds the fixture's own REST candles.
    clearRobotLiveCandles($symbol);

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

function clearRobotLiveCandles(string $symbol): void
{
    try {
        Redis::del('live-candles:binance_futures:'.strtoupper($symbol).':1m');
    } catch (Throwable) {
    }
}

function clearRobotLiveFeed(string $symbol): void
{
    $symbol = strtoupper($symbol);

    try {
        Redis::del(
            'live-feed:binance_futures:'.$symbol,
            'live-feed:binance_futures:*',
            'market:live:'.$symbol,
            'price:binance_futures:'.$symbol,
            'ts:binance_futures:'.$symbol,
        );
    } catch (Throwable) {
    }
}
