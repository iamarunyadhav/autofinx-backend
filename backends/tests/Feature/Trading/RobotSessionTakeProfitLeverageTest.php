<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\RobotSessionService;
use App\Jobs\CheckQuickTradeStopLossJob;
use App\Models\AuditEvent;
use App\Models\Integration;
use App\Models\MarketFeedStatus;
use App\Models\QuickTrade;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Robot Trade parity with Quick Trade for take-profit and leverage --
 * mirrors the exact fixture/idiom already established in
 * RobotSessionStopLossFeeAndForecastShadowTest.php for
 * quick_trade_stop_loss_pct, but with uniquely-prefixed helper function
 * names (robotTpLev*) so this file can be loaded in the same test process
 * without redeclaring that file's own global helpers.
 *
 * Both new fields are forwarded verbatim into QuickTradeService::place()'s
 * pre-existing take_profit_pct / leverage_enabled / leverage parameters --
 * no new validation or monitoring mechanism is introduced by Robot Trade
 * itself. Leverage is validated by the SAME LeverageValidationService::validate()
 * Quick Trade uses (via BinanceTimedFuturesProvider::openTimedTrade()), and
 * take-profit is monitored by the SAME unmodified CheckQuickTradeStopLossJob.
 */
function robotTpLevIntegration(string $workspaceId): Integration
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
            'api_key' => 'robot-tp-lev-key',
            'api_secret' => 'robot-tp-lev-secret',
        ])),
    ]);
}

function robotTpLevLiveSettings(string $workspaceId, string $integrationId): void
{
    foreach ([
        'trading_mode' => 'live',
        'live_enabled' => true,
        'preferred_provider' => 'binance',
        'preferred_integration_id' => $integrationId,
        'venue_environment' => 'live',
    ] as $key => $value) {
        Setting::updateOrCreate(
            ['workspace_id' => $workspaceId, 'group' => 'trading', 'key' => $key],
            ['id' => (string) Str::uuid(), 'value_json' => $value, 'encrypted' => false]
        );
    }
}

function robotTpLevFeedStatus(string $workspaceId, string $symbol, float $price): void
{
    MarketFeedStatus::updateOrCreate(
        ['workspace_id' => $workspaceId, 'symbol' => strtoupper($symbol), 'exchange' => 'binance', 'market_type' => 'futures'],
        [
            'id' => (string) Str::uuid(),
            'symbol_id' => (string) Str::uuid(),
            'last_tick_at' => now(),
            'last_price' => $price,
            'source' => 'websocket',
            'status' => 'healthy',
            'meta_json' => ['test' => true],
        ]
    );
}

function robotTpLevLiveFeed(string $symbol, float $price): void
{
    app(MarketPriceCacheService::class)->setLatestPrice(
        'binance_futures',
        strtoupper($symbol),
        $price,
        (int) floor(microtime(true) * 1000),
        ['stream' => strtolower($symbol).'@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
    );
}

/**
 * Reproduces the exact klines shape RobotSessionLiveEvaluationTest already
 * proved reliably produces a confirmed directional signal after 2
 * evaluateSession() calls (signal-stability gate needs 2 consecutive reads).
 */
function robotTpLevFakeBinance(string $symbol, array $brackets = []): void
{
    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($symbol, $brackets) {
        $url = $request->url();

        if (str_contains($url, '/fapi/v1/userTrades') || str_contains($url, '/fapi/v1/income')) {
            return Http::response([], 200);
        }
        if (str_contains($url, '/fapi/v2/balance')) {
            return Http::response([
                ['asset' => 'USDT', 'balance' => '1000.00', 'availableBalance' => '1000.00'],
            ], 200);
        }
        if (str_contains($url, '/fapi/v1/ticker/price')) {
            return Http::response(['symbol' => $symbol, 'price' => '2000.00'], 200);
        }
        if (str_contains($url, '/fapi/v1/exchangeInfo')) {
            return Http::response([
                'symbols' => [[
                    'symbol' => $symbol,
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
            return Http::response(['symbol' => $symbol, 'brackets' => $brackets], 200);
        }
        if (str_contains($url, '/fapi/v1/leverage') && $request->method() === 'POST') {
            return Http::response(['leverage' => 10, 'symbol' => $symbol], 200);
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
            $isClose = str_contains((string) $request->body(), 'reduceOnly');

            return Http::response([
                'orderId' => $isClose ? '8882' : '8881',
                'avgPrice' => $isClose ? '2040.00' : '2000.00',
                'executedQty' => '0.010',
            ], 200);
        }

        return Http::response([], 200);
    });
}

/**
 * @return array{0: Workspace, 1: \App\Models\RobotSession}
 */
function robotTpLevSessionOpenedViaRealPath(string $symbol, array $extraPayload = []): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = robotTpLevIntegration($workspace->id);
    robotTpLevLiveSettings($workspace->id, $integration->id);
    robotTpLevFeedStatus($workspace->id, $symbol, 2000.00);
    robotTpLevLiveFeed($symbol, 2000.00);

    $payload = array_merge([
        'name' => "{$symbol} tp-leverage robot",
        'symbol' => $symbol,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'indicator_set' => ['MACD'],
        'strategy_profile' => 'balanced',
        'base_stake' => 20,
        'confidence_threshold' => 50,
        'provider' => 'binance',
        'integration_id' => $integration->id,
    ], $extraPayload);

    $session = app(RobotSessionService::class)->create($workspace->id, $payload, $user->id);
    $session = app(RobotSessionService::class)->start($session);

    // Two evaluations: 1st builds the signal-stability streak, 2nd confirms
    // and actually opens the trade -- exactly what
    // RobotSessionLiveEvaluationTest already proved for this klines shape.
    app(RobotSessionService::class)->evaluateSession($session);
    $result = app(RobotSessionService::class)->evaluateSession($session->fresh());

    expect($result['status'])->toBe('placed');

    return [$workspace, $session->fresh()];
}

// ── Take-profit parity ──────────────────────────────────────────────────────

test('a Robot Trade session with quick_trade_take_profit_pct configured opens a QuickTrade carrying that value, and the UNCHANGED CheckQuickTradeStopLossJob settles it early on a favourable move', function () {
    $symbol = 'UNIUSDT';
    robotTpLevFakeBinance($symbol);

    [, $session] = robotTpLevSessionOpenedViaRealPath($symbol, ['quick_trade_take_profit_pct' => 2.00]);

    $trade = QuickTrade::where('robot_session_id', $session->id)->firstOrFail();
    expect((float) $trade->take_profit_pct)->toEqualWithDelta(2.00, 0.0000001);
    expect($trade->status)->toBe('open');
    expect($trade->source)->toBe('robot_trade');

    // Price rises from 2000 to 2040 -- a 2% favourable move, right at the
    // configured +2% threshold. The job below is queried purely off
    // (status=open, mode=live, take_profit_pct IS NOT NULL) with zero
    // dependency on robot_session_id or how the trade was opened.
    robotTpLevLiveFeed($symbol, 2040.0);

    CheckQuickTradeStopLossJob::dispatchSync();

    $fresh = $trade->fresh();
    expect($fresh->status)->not->toBe('open');
    expect($fresh->take_profit_triggered_at)->not->toBeNull();

    $auditEvent = AuditEvent::where('event_type', 'quick_trade_take_profit_triggered')
        ->where('symbol', $symbol)
        ->latest('created_at')
        ->first();
    expect($auditEvent)->not->toBeNull();
    expect($auditEvent->metadata['quick_trade_id'] ?? null)->toBe($trade->id);
});

test('a Robot Trade session with no quick_trade_take_profit_pct configured opens a QuickTrade the take-profit job never touches', function () {
    $symbol = 'ATOMUSDT';
    robotTpLevFakeBinance($symbol);

    [, $session] = robotTpLevSessionOpenedViaRealPath($symbol);

    $trade = QuickTrade::where('robot_session_id', $session->id)->firstOrFail();
    expect($trade->take_profit_pct)->toBeNull();

    // A large favourable move that would trigger almost any realistic
    // take-profit threshold if one were configured.
    robotTpLevLiveFeed($symbol, 2200.0);

    CheckQuickTradeStopLossJob::dispatchSync();

    $fresh = $trade->fresh();
    expect($fresh->status)->toBe('open');
    expect($fresh->take_profit_triggered_at)->toBeNull();
});

test('creating a Robot Trade session rejects a negative or out-of-range quick_trade_take_profit_pct, same as Quick Trade', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();

    $response = $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/robot-sessions", [
        'name' => 'Bad TP config robot',
        'symbol' => 'NEARUSDT',
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'base_stake' => 20,
        'quick_trade_take_profit_pct' => -2.00, // negative -- must be rejected
    ]);

    $response->assertStatus(422);
});

// ── Leverage parity ─────────────────────────────────────────────────────────

test('a Robot Trade session with leverage enabled within the real exchange limit opens a leveraged QuickTrade via the SAME LeverageValidationService Quick Trade uses', function () {
    $symbol = 'FILUSDT';
    robotTpLevFakeBinance($symbol, [
        ['bracket' => 1, 'initialLeverage' => 20, 'notionalCap' => 50000, 'notionalFloor' => 0, 'maintMarginRatio' => 0.005],
    ]);

    [, $session] = robotTpLevSessionOpenedViaRealPath($symbol, [
        'quick_trade_leverage_enabled' => true,
        'quick_trade_leverage' => 10,
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverageBracket'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');

    $trade = QuickTrade::where('robot_session_id', $session->id)->firstOrFail();
    expect((bool) $trade->leverage_enabled)->toBeTrue()
        ->and((float) $trade->leverage)->toEqualWithDelta(10.0, 0.0001);
});

test('a Robot Trade session requesting leverage the real exchange bracket does not support for that notional is rejected, zero trade placed', function () {
    $symbol = 'ICPUSDT';
    robotTpLevFakeBinance($symbol, [
        ['bracket' => 1, 'initialLeverage' => 15, 'notionalCap' => 50000, 'notionalFloor' => 0, 'maintMarginRatio' => 0.005],
    ]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = robotTpLevIntegration($workspace->id);
    robotTpLevLiveSettings($workspace->id, $integration->id);
    robotTpLevFeedStatus($workspace->id, $symbol, 2000.00);
    robotTpLevLiveFeed($symbol, 2000.00);

    $session = app(RobotSessionService::class)->create($workspace->id, [
        'name' => "{$symbol} over-leveraged robot",
        'symbol' => $symbol,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'indicator_set' => ['MACD'],
        'strategy_profile' => 'balanced',
        'base_stake' => 20,
        'confidence_threshold' => 50,
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'quick_trade_leverage_enabled' => true,
        'quick_trade_leverage' => 18, // exceeds the real 15x bracket max for this notional
    ], $user->id);
    $session = app(RobotSessionService::class)->start($session);

    // 1st evaluation builds the signal-stability streak (no order attempt yet).
    app(RobotSessionService::class)->evaluateSession($session);
    // 2nd evaluation confirms the signal and attempts the real order --
    // QuickTradeService::place() must hard-reject before any order is sent.
    // evaluateActiveSessions() is used here (rather than calling
    // evaluateSession() directly) because it's the real caller-facing entry
    // point (scheduler/daemon) that wraps evaluateSession() in a try/catch
    // and turns an uncaught RuntimeException into a graceful
    // 'runtime_exception_block' result instead of a fatal error.
    $results = app(RobotSessionService::class)->evaluateActiveSessions($session->fresh()->id);
    $result = $results[0];

    expect($result['status'])->toBe('runtime_exception_block');
    expect($result['reason'])->toContain('exceeds_exchange_max_leverage_for_notional');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/order') && $request->method() === 'POST');
    expect(QuickTrade::where('robot_session_id', $session->id)->count())->toBe(0);
});

test('a Robot Trade session with leverage left unset (default off) behaves byte-for-byte as before: no leverage stored, no leverage-bracket lookup', function () {
    $symbol = 'APTUSDT';
    robotTpLevFakeBinance($symbol);

    [, $session] = robotTpLevSessionOpenedViaRealPath($symbol);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverageBracket'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/leverage') && $request->method() === 'POST');

    $trade = QuickTrade::where('robot_session_id', $session->id)->firstOrFail();
    expect((bool) $trade->leverage_enabled)->toBeFalse()
        ->and($trade->leverage)->toBeNull()
        ->and($trade->take_profit_pct)->toBeNull();
});
