<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\MarketIntelligence\UnifiedForecastEngine;
use App\Domain\Ops\FeatureFlagService;
use App\Domain\Trading\QuickTradeService;
use App\Domain\Trading\RobotSessionService;
use App\Jobs\CheckQuickTradeStopLossJob;
use App\Models\AuditEvent;
use App\Models\Integration;
use App\Models\MarketFeedStatus;
use App\Models\MarketOhlc;
use App\Models\QuickTrade;
use App\Models\RatioBotForecastBacktest;
use App\Models\Setting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Robot Trade parity with Quick Trade for three features built for Quick
 * Trade this session: stop-loss (real, opt-in), the fee-accuracy fix
 * (verified to already cover this call path, no code change expected), and
 * the Unified Forecast Engine shadow-wiring (shadow only, exactly like
 * Quick Trade's Phase 5).
 *
 * Each test uses its OWN symbol -- MarketPriceCacheService's Redis-backed
 * live-feed cache is keyed globally per (exchange, symbol), not scoped by
 * workspace/test, and is NOT reset by RefreshDatabase between tests. Its own
 * anti-spoof guard (rejects a >5% single-step jump within a 5s window)
 * means two tests sharing one symbol back-to-back can silently leave a
 * stale price behind for the next test. Distinct symbols per test sidestep
 * this pre-existing test-isolation characteristic entirely rather than
 * fighting it.
 */
function robotParityIntegration(string $workspaceId): Integration
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
            'api_key' => 'robot-parity-key',
            'api_secret' => 'robot-parity-secret',
        ])),
    ]);
}

function robotParityLiveSettings(string $workspaceId, string $integrationId): void
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

function robotParityFeedStatus(string $workspaceId, string $symbol, float $price): void
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

function robotParityLiveFeed(string $symbol, float $price): void
{
    // Redis is not reset by RefreshDatabase.  Keep the candle context owned by
    // this fixture rather than accepting a previous test's global cache.
    try {
        \Illuminate\Support\Facades\Redis::del('live-candles:binance_futures:'.strtoupper($symbol).':1m');
    } catch (Throwable) {
    }

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
function robotParityFakeBinance(string $symbol, ?\Closure $userTradesAndIncomeHandler = null): void
{
    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($symbol, $userTradesAndIncomeHandler) {
        $url = $request->url();

        if ($userTradesAndIncomeHandler && (str_contains($url, '/fapi/v1/userTrades') || str_contains($url, '/fapi/v1/income'))) {
            $response = $userTradesAndIncomeHandler($url);
            if ($response !== null) {
                return $response;
            }
        }
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
            $isClose = str_contains((string) $request->body(), 'reduceOnly');

            return Http::response([
                'orderId' => $isClose ? '7772' : '7771',
                'avgPrice' => $isClose ? '1980.00' : '2000.00',
                'executedQty' => '0.010',
            ], 200);
        }

        return Http::response([], 200);
    });
}

/**
 * @return array{0: Workspace, 1: \App\Models\RobotSession}
 */
function robotParitySessionOpenedViaRealPath(string $symbol, ?float $quickTradeStopLossPct = null): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $integration = robotParityIntegration($workspace->id);
    robotParityLiveSettings($workspace->id, $integration->id);
    robotParityFeedStatus($workspace->id, $symbol, 2000.00);
    robotParityLiveFeed($symbol, 2000.00);

    $payload = [
        'name' => "{$symbol} parity robot",
        'symbol' => $symbol,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'indicator_set' => ['MACD'],
        'strategy_profile' => 'balanced',
        'base_stake' => 20,
        'confidence_threshold' => 50,
        'provider' => 'binance',
        'integration_id' => $integration->id,
    ];
    if ($quickTradeStopLossPct !== null) {
        $payload['quick_trade_stop_loss_pct'] = $quickTradeStopLossPct;
    }

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

// ── Part 1: Robot Trade stop-loss ──────────────────────────────────────────

test('a Robot Trade session with quick_trade_stop_loss_pct configured opens a QuickTrade carrying that value, and the UNCHANGED CheckQuickTradeStopLossJob settles it early on a breach', function () {
    $symbol = 'ETHUSDT';
    robotParityFakeBinance($symbol);

    [, $session] = robotParitySessionOpenedViaRealPath($symbol, -1.00);

    $trade = QuickTrade::where('robot_session_id', $session->id)->firstOrFail();
    expect((float) $trade->stop_loss_pct)->toEqualWithDelta(-1.00, 0.0000001);
    expect($trade->status)->toBe('open');
    expect($trade->source)->toBe('robot_trade');

    // Price drops from 2000 to 1980 -- a 1% adverse move, right at the
    // configured -1% threshold. The job below is queried purely off
    // (status=open, mode=live, stop_loss_pct IS NOT NULL) with zero
    // dependency on robot_session_id or how the trade was opened.
    robotParityLiveFeed($symbol, 1980.0);

    CheckQuickTradeStopLossJob::dispatchSync();

    $fresh = $trade->fresh();
    expect($fresh->status)->not->toBe('open');
    expect($fresh->stop_loss_triggered_at)->not->toBeNull();

    $auditEvent = AuditEvent::where('event_type', 'quick_trade_stop_loss_triggered')
        ->where('symbol', $symbol)
        ->latest('created_at')
        ->first();
    expect($auditEvent)->not->toBeNull();
    expect($auditEvent->metadata['quick_trade_id'] ?? null)->toBe($trade->id);
});

test('a Robot Trade session with no quick_trade_stop_loss_pct configured opens a QuickTrade the stop-loss job never touches', function () {
    $symbol = 'BNBUSDT';
    robotParityFakeBinance($symbol);

    [, $session] = robotParitySessionOpenedViaRealPath($symbol, null);

    $trade = QuickTrade::where('robot_session_id', $session->id)->firstOrFail();
    expect($trade->stop_loss_pct)->toBeNull();

    // A large adverse move that would trigger almost any realistic
    // stop-loss threshold if one were configured.
    robotParityLiveFeed($symbol, 1900.0);

    CheckQuickTradeStopLossJob::dispatchSync();

    $fresh = $trade->fresh();
    expect($fresh->status)->toBe('open');
    expect($fresh->stop_loss_triggered_at)->toBeNull();
});

test('creating a Robot Trade session rejects a positive or out-of-range quick_trade_stop_loss_pct, same as Quick Trade', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    $response = $this->actingAs($user)->postJson("/api/v1/workspaces/{$workspace->id}/robot-sessions", [
        'name' => 'Bad config robot',
        'symbol' => 'SOLUSDT',
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'base_stake' => 20,
        'quick_trade_stop_loss_pct' => 2.00, // positive -- must be rejected
    ]);

    $response->assertStatus(422);
});

// ── Part 2: fee-accuracy fix coverage ──────────────────────────────────────

test('a live trade opened via RobotSessionService real call path settles with the same real-fee reconciliation as a directly-placed Quick Trade', function () {
    $symbol = 'ADAUSDT';
    robotParityFakeBinance($symbol, function (string $url) use ($symbol) {
        if (str_contains($url, '/fapi/v1/userTrades')) {
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            $orderId = $query['orderId'] ?? null;
            $commission = $orderId === '7772' ? '0.06000000' : '0.05000000';

            return Http::response([[
                'symbol' => $symbol,
                'id' => 1,
                'orderId' => (int) $orderId,
                'side' => $orderId === '7772' ? 'SELL' : 'BUY',
                'price' => $orderId === '7772' ? '2010.00' : '2000.00',
                'qty' => '0.010',
                'realizedPnl' => '0',
                'commission' => $commission,
                'commissionAsset' => 'USDT',
                'time' => now()->getTimestampMs(),
            ]], 200);
        }
        if (str_contains($url, '/fapi/v1/income')) {
            return Http::response([[
                'symbol' => $symbol,
                'incomeType' => 'FUNDING_FEE',
                'income' => '-0.02000000',
                'asset' => 'USDT',
                'time' => now()->getTimestampMs(),
            ]], 200);
        }

        return null;
    });

    [, $session] = robotParitySessionOpenedViaRealPath($symbol);
    $trade = QuickTrade::where('robot_session_id', $session->id)->firstOrFail();
    expect($trade->fee_source)->toBe('estimated'); // column default before settlement

    // settleTrade() is the exact same generic path CheckQuickTradeStopLossJob
    // and settleExpired() both call -- not a second/duplicate settlement
    // mechanism for Robot-Trade-opened trades.
    $settled = app(QuickTradeService::class)->settleTrade($trade->fresh());

    expect($settled->status)->not->toBe('open');
    expect($settled->fee_source)->toBe('real');
    expect((float) $settled->entry_fee_actual)->toEqualWithDelta(0.05, 0.0000001);
    expect((float) $settled->exit_fee_actual)->toEqualWithDelta(0.06, 0.0000001);
    expect((float) $settled->funding_fee_actual)->toEqualWithDelta(0.02, 0.0000001);
    expect($settled->gross_pnl)->not->toBeNull();
    expect((float) $settled->fees_total)->toEqualWithDelta(0.13, 0.0000001);
});

// ── Part 3: Unified Forecast Engine shadow-wiring ───────────────────────────

test('RobotSessionService real evaluation behavior is decision-identical whether the robot_trade shadow flag is on or off', function () {
    app(FeatureFlagService::class)->set('unified_forecast.robot_trade_shadow', true);
    robotParityFakeBinance('XRPUSDT');
    [, $sessionOn] = robotParitySessionOpenedViaRealPath('XRPUSDT');
    $tradeOn = QuickTrade::where('robot_session_id', $sessionOn->id)->firstOrFail();

    app(FeatureFlagService::class)->set('unified_forecast.robot_trade_shadow', false);
    robotParityFakeBinance('LTCUSDT');
    [, $sessionOff] = robotParitySessionOpenedViaRealPath('LTCUSDT');
    $tradeOff = QuickTrade::where('robot_session_id', $sessionOff->id)->firstOrFail();

    expect($tradeOn->side)->toBe($tradeOff->side);
    expect((float) $tradeOn->stake_amount)->toEqualWithDelta((float) $tradeOff->stake_amount, 0.0000001);
    expect((float) $tradeOn->entry_price)->toEqualWithDelta((float) $tradeOff->entry_price, 0.0000001);
    expect($sessionOn->fresh()->status)->toBe($sessionOff->fresh()->status);
});

test('flag on records a robot_trade unified_forecast_shadow_comparison AuditEvent with both old and new results', function () {
    $symbol = 'DOTUSDT';
    app(FeatureFlagService::class)->set('unified_forecast.robot_trade_shadow', true);
    robotParityFakeBinance($symbol);

    $this->assertDatabaseCount('audit_events', 0);
    [, $session] = robotParitySessionOpenedViaRealPath($symbol);

    $event = AuditEvent::where('event_type', 'unified_forecast_shadow_comparison')
        ->where('bot_type', 'robot_trade')
        ->first();

    expect($event)->not->toBeNull();
    expect($event->symbol)->toBe($symbol);
    expect($event->metadata)->toHaveKey('old');
    expect($event->metadata)->toHaveKey('new');
    expect($event->metadata['robot_session_id'] ?? null)->toBe($session->id);
    expect($event->metadata['new'])->toHaveKey('direction');
});

test('flag off never calls UnifiedForecastEngine at all for Robot Trade', function () {
    $symbol = 'AVAXUSDT';
    app(FeatureFlagService::class)->set('unified_forecast.robot_trade_shadow', false);
    robotParityFakeBinance($symbol);

    $spy = Mockery::mock(UnifiedForecastEngine::class);
    $spy->shouldNotReceive('evaluate');
    app()->instance(UnifiedForecastEngine::class, $spy);

    robotParitySessionOpenedViaRealPath($symbol);

    // Robot Trade's own (unrelated) audit instrumentation -- entry gates,
    // decision recording, etc. -- still fires regardless of this flag; only
    // the shadow-comparison event type must be absent.
    $this->assertDatabaseMissing('audit_events', ['event_type' => 'unified_forecast_shadow_comparison']);
});

test('ForecastShadowReportService surfaces robot_trade shadow events generically, with zero code change needed', function () {
    $symbol = 'LINKUSDT';
    app(FeatureFlagService::class)->set('unified_forecast.robot_trade_shadow', true);
    robotParityFakeBinance($symbol);
    robotParitySessionOpenedViaRealPath($symbol);

    $before = AuditEvent::where('event_type', 'unified_forecast_shadow_comparison')->count();
    expect($before)->toBeGreaterThan(0);

    $report = app(\App\Domain\MarketIntelligence\ForecastShadowReportService::class)->report(7, $symbol);

    expect($report['summary']['total_events'])->toBe($before);
});

// ── Part 4: robot-trade:forecast-backtest periodic calibration tracking ────
//
// Tracking-only wiring of Robot Trade into
// ForecastHorizonBacktestService::runForBot() (Unified Forecast Engine plan,
// Robot Trade/Future Trade/Fast2X task). Proves the periodic command writes
// a correctly-tagged calibration row AND never touches the real session or
// its real trade -- it is a separate scheduled command
// (routes/console.php), never called from RobotSessionService::evaluateSession()
// or any other live decision path.

function seedRobotTradeCalibrationTrend(string $exchange, string $symbol, string $timeframe, float $start, float $step, int $count): void
{
    $time = now()->subMinutes($count + 1);
    for ($i = 0; $i < $count; $i++) {
        $open = $start + ($step * $i);
        $close = $open + $step;
        MarketOhlc::create([
            'exchange' => $exchange,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'open' => $open,
            'high' => max($open, $close) + 1,
            'low' => min($open, $close) - 1,
            'close' => $close,
            'volume' => 10,
            'ts' => $time->copy()->addMinutes($i),
        ]);
    }
}

test('robot-trade:forecast-backtest writes a calibration row tagged with the real session id and never mutates the session or its real trade', function () {
    $symbol = 'CHZUSDT';
    seedRobotTradeCalibrationTrend('binance', $symbol, '1m', 10.0, 0.05, 400);

    robotParityFakeBinance($symbol);
    [, $session] = robotParitySessionOpenedViaRealPath($symbol);
    $trade = QuickTrade::where('robot_session_id', $session->id)->firstOrFail();

    $sessionBefore = $session->fresh()->getAttributes();
    $tradeBefore = $trade->fresh()->getAttributes();

    $this->artisan('robot-trade:forecast-backtest')->assertExitCode(0);

    $rows = RatioBotForecastBacktest::query()->where('bot_type', 'robot_trade')->get();
    expect($rows->count())->toBeGreaterThan(0);
    foreach ($rows as $row) {
        expect($row->bot_instance_id)->not->toBeNull();
        expect($row->bot_instance_id)->toBe($session->id);
    }

    expect($session->fresh()->getAttributes())->toBe($sessionBefore);
    expect($trade->fresh()->getAttributes())->toBe($tradeBefore);
});

test('robot-trade:forecast-backtest does not change RobotSessionService::evaluateSession real decision behavior', function () {
    $symbol = 'ENJUSDT';
    seedRobotTradeCalibrationTrend('binance', $symbol, '1m', 5.0, 0.02, 400);

    robotParityFakeBinance($symbol);
    [, $session] = robotParitySessionOpenedViaRealPath($symbol);

    // Running the tracking command AFTER the trade already opened must not
    // retroactively change anything about the session's live evaluation
    // outcome -- re-evaluating afterwards must behave exactly like Robot
    // Trade already does post-entry (an active_side/pending-trade session
    // just reports its current status, since evaluateTimedExpirySession()'s
    // active-trade-limit gate blocks a second concurrent trade regardless).
    $beforeReEval = app(RobotSessionService::class)->evaluateSession($session->fresh());

    $this->artisan('robot-trade:forecast-backtest')->assertExitCode(0);

    $afterReEval = app(RobotSessionService::class)->evaluateSession($session->fresh());

    expect($afterReEval['status'])->toBe($beforeReEval['status']);
    expect($afterReEval['action'])->toBe($beforeReEval['action']);
});
