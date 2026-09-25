<?php

use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Ops\FeatureFlagService;
use App\Domain\Trading\CandleCloseResolver;
use App\Domain\Trading\CandleDirectionBotService;
use App\Domain\Trading\RobotSessionService;
use App\Domain\Trading\TradeForecastService;
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

/**
 * Canonical fee-aware trade_decision gate for Robot Trade LIVE entries
 * (`robot_trade.block_live_against_decision`, default OFF).
 *
 * Proves, against the REAL TradeForecastService / CandleCloseResolver /
 * QuickTradeService stack (only Binance HTTP is faked):
 *  - the default preserves today's behaviour exactly (a live robot still
 *    opens on a market where Robot's own netEdge() says "tradeable" but the
 *    canonical trade_decision says WAIT -- the divergence this gate closes);
 *  - with the flag on, the robot is final-gated by the same trade_decision
 *    Quick Trade's live guard uses, for both bot modes, fail-closed;
 *  - Robot Trade's own earlier guards (net-edge, stale feed) still run first
 *    and still win, unchanged.
 *
 * Helpers are prefixed `rtdg` so this file never collides with (or depends
 * on the load order of) RobotSessionLiveEvaluationTest's global helpers.
 */
function rtdgKlines(string $shape, bool $fresh): array
{
    $rows = [];
    $nowMinuteMs = intdiv((int) floor(microtime(true) * 1000), 60000) * 60000;
    for ($i = 0; $i < 100; $i++) {
        // fresh: the last row is the still-forming bar of the current
        // minute, every earlier one is closed and contiguous (no gap).
        // stale: the long-standing fixture timestamps (Nov 2023).
        $openTime = $fresh ? $nowMinuteMs - ((99 - $i) * 60000) : 1700000000000 + ($i * 60000);

        if ($shape === 'uptrend') {
            // The same rising-close shape the existing Robot live tests use.
            $open = 1701 + ($i * 3);
            $close = $open + 2;
            $high = $close + 20;
            $low = $open - 20;
        } elseif ($shape === 'forming_drop') {
            // The uptrend above, except the still-forming (last) bar is a
            // 40-point drop: MACD on all 100 bars votes SELL (76), MACD on
            // the 99 closed bars votes BUY (57).
            $open = 1701 + ($i * 3);
            $close = $i === 99 ? $open - 40 : $open + 2;
            $high = max($open, $close) + 20;
            $low = min($open, $close) - 20;
        } elseif ($shape === 'downtrend') {
            $open = 2297 - ($i * 3);
            $close = $open - 2;
            $high = $open + 15;
            $low = $close - 15;
        } else {
            // 'divergent': a slow uptrend of long-wick doji bars. The wide
            // ranges give an ATR (~1.55%) where Robot's direction-blind,
            // horizon-blind netEdge() proxy (ATR x 0.35 = ~0.54%) clears the
            // 0.28% cost floor and calls it tradeable, while the canonical
            // forecast sees no candle-body trend (dojis are NEUTRAL) and a
            // tiny drift, so its expected 1-candle move (~0.23%) cannot clear
            // fees (WAIT). Previously this fixture relied on the old
            // oldest-20-bars ATR bug (volatile OLD bars, calm recent ones);
            // with `trading_forecast.atr_latest_bars` on, both reads see the
            // same recent bars and the divergence is purely model-driven.
            // The +/-1.0 close zigzag (latest bar up) keeps Robot's own
            // Bollinger-width volatility gate open and its MACD vote BUY
            // without adding any candle-body trend.
            $open = 1950 + ($i * 0.1);
            $close = $open + 0.4 + ($i % 2 === 1 ? 1.0 : -1.0);
            $wick = 15.0;
            $high = max($open, $close) + $wick;
            $low = min($open, $close) - $wick;
        }

        $rows[] = [$openTime, (string) $open, (string) $high, (string) $low, (string) $close, '100'];
    }

    return $rows;
}

/** Same shape BinanceTimedFuturesProvider::candles() maps klines into. */
function rtdgCandles(array $rows): array
{
    return array_map(fn (array $row) => [
        'timestamp' => (int) $row[0],
        'open' => (float) $row[1],
        'high' => (float) $row[2],
        'low' => (float) $row[3],
        'close' => (float) $row[4],
        'volume' => (float) $row[5],
    ], $rows);
}

function rtdgFakeBinance(array $klines, ?array $commission = null): void
{
    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($klines, $commission) {
        $url = $request->url();

        if ($commission !== null && str_contains($url, '/fapi/v1/commissionRate')) {
            return Http::response($commission, 200);
        }
        if (str_contains($url, '/fapi/v1/userTrades') || str_contains($url, '/fapi/v1/income')) {
            return Http::response([], 200);
        }
        if (str_contains($url, '/fapi/v2/balance')) {
            return Http::response([['asset' => 'USDT', 'balance' => '1000.00', 'availableBalance' => '1000.00']], 200);
        }
        if (str_contains($url, '/fapi/v1/ticker/price')) {
            return Http::response(['price' => '2000.00'], 200);
        }
        if (str_contains($url, '/fapi/v1/exchangeInfo')) {
            return Http::response(['symbols' => [[
                'filters' => [
                    ['filterType' => 'MARKET_LOT_SIZE', 'stepSize' => '0.001', 'minQty' => '0.001'],
                    ['filterType' => 'MIN_NOTIONAL', 'notional' => '20.00000000'],
                ],
            ]]], 200);
        }
        if (str_contains($url, '/fapi/v1/klines')) {
            return Http::response($klines, 200);
        }
        if (str_contains($url, '/fapi/v1/order') && $request->method() === 'POST') {
            return Http::response(['orderId' => '8801', 'avgPrice' => '2000.00', 'executedQty' => '0.010'], 200);
        }

        return Http::response([], 200);
    });
}

function rtdgLiveWorkspace(string $symbol, bool $freshFeed = true): array
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
            'api_key' => 'rtdg-key', 'api_secret' => 'rtdg-secret',
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

    // Fresh server feed (DB row + Redis live feed), exactly like the
    // existing Robot live tests -- this gate is not about feed freshness.
    // $freshFeed = false leaves only a 120s-old DB tick and no Redis tick.
    MarketFeedStatus::updateOrCreate(
        ['workspace_id' => $workspace->id, 'symbol' => $symbol, 'exchange' => 'binance', 'market_type' => 'futures'],
        [
            'id' => (string) Str::uuid(), 'symbol_id' => (string) Str::uuid(),
            'last_tick_at' => $freshFeed ? now() : now()->subSeconds(120), 'last_price' => 2000.0, 'source' => 'websocket',
            'status' => 'healthy', 'meta_json' => ['test' => true],
        ]
    );
    try {
        Redis::del('live-candles:binance_futures:'.$symbol.':1m');
    } catch (Throwable) {
    }
    if ($freshFeed) {
        app(MarketPriceCacheService::class)->setLatestPrice(
            'binance_futures', $symbol, 2000.0, (int) floor(microtime(true) * 1000),
            ['stream' => strtolower($symbol).'@trade', 'event_type' => 'trade', 'market_type' => 'futures'],
        );
    }

    return [$user, $workspace, $integration];
}

function rtdgStartSession(string $symbol, string $botMode = 'timed_expiry', bool $freshFeed = true, float $baseStake = 20): RobotSession
{
    [$user, $workspace, $integration] = rtdgLiveWorkspace($symbol, $freshFeed);

    // Robot Trade's own real platform defaults: 1m chart, 60s expiry.
    $session = app(RobotSessionService::class)->create($workspace->id, [
        'name' => "{$symbol} gate robot",
        'symbol' => $symbol,
        'timeframe' => '1m',
        'expiry_seconds' => 60,
        'indicator_set' => ['MACD'],
        'strategy_profile' => 'balanced',
        'base_stake' => $baseStake,
        'confidence_threshold' => 50,
        'provider' => 'binance',
        'integration_id' => $integration->id,
        'bot_mode' => $botMode,
    ], $user->id);

    return app(RobotSessionService::class)->start($session);
}

/** timed_expiry needs two consecutive agreeing ticks (signal stability gate). */
function rtdgEvaluateTwice(RobotSession $session): array
{
    app(RobotSessionService::class)->evaluateSession($session);

    return app(RobotSessionService::class)->evaluateSession($session->fresh());
}

function rtdgMockCandleBotEntry(string $action): void
{
    // Partial: only the entry decision is scripted. candleDirection() stays
    // real because TradeForecastService::forecast() calls it too.
    test()->partialMock(CandleDirectionBotService::class, function ($mock) use ($action) {
        $mock->shouldReceive('evaluateCandle')->andReturn([
            'action' => $action,
            'reason' => 'Entry [body]: scripted for the trade-decision gate test.',
            'confidence' => 0.8,
            'pattern' => 'body',
            'signal' => [
                'side' => $action, 'confidence' => 0.8, 'pattern' => 'body',
                'noisy_candle' => false, 'reasons' => ['scripted'],
            ],
        ]);
    });
}

function rtdgNoOrderSent(): void
{
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/order') && $request->method() === 'POST');
}

beforeEach(function () {
    // Keep the Unified Forecast shadow engine out of these assertions; it is
    // observational only and has its own test file.
    app(FeatureFlagService::class)->set('unified_forecast.robot_trade_shadow', false);
    app(FeatureFlagService::class)->set('unified_forecast.robot_trade_live', false);
});

// ── Default: today's behaviour is preserved ─────────────────────────────

test('the canonical robot trade-decision gate ships OFF by default', function () {
    expect(config('robot_trade.block_live_against_decision'))->toBeFalse();
});

test('with the gate off a live robot still opens where netEdge says tradeable but the canonical trade_decision says WAIT', function () {
    $symbol = 'RTDGOFFUSDT';
    $klines = rtdgKlines('divergent', true);
    rtdgFakeBinance($klines);

    // The divergence itself, computed independently with the real services
    // on the exact candles the robot will see: Robot's own fee check passes,
    // the canonical fee-aware decision (Quick Trade's call shape) is WAIT.
    $candles = rtdgCandles($klines);
    $forecasts = app(TradeForecastService::class);
    expect($forecasts->netEdge($candles, 30.0)['tradeable'])->toBeTrue();
    $timing = app(CandleCloseResolver::class)->resolve($candles, '1m');
    $canonical = $forecasts->forecast($timing['candles'], '1m', 60, ['action' => 'BUY', 'confidence' => 100], null, null, null, null, $timing);
    expect($canonical['trade_decision']['value'])->toBe('WAIT')
        ->and($canonical['trade_decision']['gate_reason'])->toBeNull();

    $session = rtdgStartSession($symbol);
    $result = rtdgEvaluateTwice($session);

    expect($result['status'])->toBe('placed');
    $trade = QuickTrade::where('robot_session_id', $session->id)->firstOrFail();
    expect($trade->side)->toBe('buy')
        ->and($trade->mode)->toBe('live')
        // Flag off: nothing is computed or added -- signal_payload unchanged.
        ->and(array_key_exists('trade_decision_gate', (array) $trade->signal_payload))->toBeFalse();
});

// ── Gate on: timed_expiry ───────────────────────────────────────────────

test('with the gate on a live robot is refused when the canonical trade_decision is WAIT, with the real numbers', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    $symbol = 'RTDGWAITUSDT';
    rtdgFakeBinance(rtdgKlines('divergent', true));

    $session = rtdgStartSession($symbol);
    $result = rtdgEvaluateTwice($session);

    expect($result['status'])->toBe('trade_decision_block')
        ->and($result['action'])->toBe('skipped')
        ->and($result['side'])->toBe('buy')
        ->and($result['reason'])->toContain('robot_trade_blocked_against_decision')
        ->and($result['reason'])->toContain('WAIT')
        // required = (taker 0.0005 x 2 x 100 + slippage 0.04) x multiplier 2.0,
        // all read from config -- never a literal in the gate itself.
        ->and($result['reason'])->toContain('required 0.280%');

    $gate = $result['trade_decision_gate'];
    expect($gate['source'])->toBe('TradeForecastService::forecast')
        ->and($gate['value'])->toBe('WAIT')
        ->and($gate['agrees'])->toBeFalse()
        ->and($gate['trade_decision']['fee_aware'])->toBeTrue()
        ->and($gate['trade_decision']['cost_pct'])->toBe(0.14)
        ->and($gate['trade_decision']['required_move_pct'])->toBe(0.28)
        ->and($gate['trade_decision']['fee_rate_source'])->toBe('config')
        ->and($gate['horizon_candles'])->toBe(1);

    expect(QuickTrade::where('robot_session_id', $session->id)->exists())->toBeFalse();
    rtdgNoOrderSent();

    // Surfaced through the existing entry-block channel the Robot page renders.
    $stored = RobotSession::findOrFail($session->id);
    expect(data_get($stored->runtime_json, 'latest_entry_block.status'))->toBe('trade_decision_block')
        ->and($stored->last_error)->toContain('robot_trade_blocked_against_decision');
});

test('the gate uses the multiplier from config, so raising it moves the required move without any code change', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    config()->set('robot_trade.live_min_edge_cost_multiplier', 3.0);
    $symbol = 'RTDGMULTUSDT';
    rtdgFakeBinance(rtdgKlines('divergent', true));

    // A 3x multiplier also raises Robot's own netEdge() floor (0.42%); at the
    // default 20 USDT stake its absolute 0.03 USDT net floor would then block
    // first, so this test uses a larger stake to reach the gate itself.
    $result = rtdgEvaluateTwice(rtdgStartSession($symbol, 'timed_expiry', true, 40));

    expect($result['status'])->toBe('trade_decision_block')
        ->and($result['trade_decision_gate']['trade_decision']['required_move_pct'])->toBe(0.42)
        ->and($result['reason'])->toContain('required 0.420%');
});

test('with the gate on a live robot opens when the canonical trade_decision agrees, and records the decision it was gated on', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    $symbol = 'RTDGAGREEUSDT';
    rtdgFakeBinance(rtdgKlines('uptrend', true));

    $session = rtdgStartSession($symbol);
    $result = rtdgEvaluateTwice($session);

    expect($result['status'])->toBe('placed');
    $trade = QuickTrade::where('robot_session_id', $session->id)->firstOrFail();
    expect($trade->side)->toBe('buy')
        ->and(data_get($trade->signal_payload, 'trade_decision_gate.value'))->toBe('BUY')
        ->and(data_get($trade->signal_payload, 'trade_decision_gate.agrees'))->toBeTrue()
        ->and(data_get($trade->signal_payload, 'trade_decision_gate.trade_decision.fee_aware'))->toBeTrue();
});

test('with the gate on, candles that are not current force WAIT through the same candle_gap gate Quick Trade uses', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    $symbol = 'RTDGGAPUSDT';
    // Same strong uptrend that passes above, but every bar is from 2023.
    rtdgFakeBinance(rtdgKlines('uptrend', false));

    $session = rtdgStartSession($symbol);
    $result = rtdgEvaluateTwice($session);

    expect($result['status'])->toBe('trade_decision_block')
        ->and($result['trade_decision_gate']['trade_decision']['gate_reason'])->toBe('candle_gap')
        ->and($result['trade_decision_gate']['candle_gap'])->toBeTrue()
        ->and($result['reason'])->toContain('Gate: candle_gap');
    expect(QuickTrade::where('robot_session_id', $session->id)->exists())->toBeFalse();
    rtdgNoOrderSent();
});

test('with the gate on an unavailable trade decision blocks the entry (fail-closed)', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    $symbol = 'RTDGNAUSDT';
    rtdgFakeBinance(rtdgKlines('uptrend', true));
    $this->partialMock(TradeForecastService::class, function ($mock) {
        $mock->shouldReceive('forecast')->andReturn([
            'available' => false,
            'summary' => 'Not enough fresh candles to estimate the next move.',
        ]);
    });

    $session = rtdgStartSession($symbol);
    $result = rtdgEvaluateTwice($session);

    expect($result['status'])->toBe('trade_decision_block')
        ->and($result['reason'])->toContain('robot_trade_blocked_trade_decision_unavailable')
        ->and($result['trade_decision_gate']['value'])->toBeNull();
    expect(QuickTrade::where('robot_session_id', $session->id)->exists())->toBeFalse();
    rtdgNoOrderSent();
});

test('with the gate on a forecast exception blocks the entry instead of trading on no answer', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    $symbol = 'RTDGTHROWUSDT';
    rtdgFakeBinance(rtdgKlines('uptrend', true));
    $this->partialMock(TradeForecastService::class, function ($mock) {
        $mock->shouldReceive('forecast')->andThrow(new RuntimeException('forecast engine down'));
    });

    $session = rtdgStartSession($symbol);
    $result = rtdgEvaluateTwice($session);

    expect($result['status'])->toBe('trade_decision_block')
        ->and($result['reason'])->toContain('forecast engine down');
    expect(QuickTrade::where('robot_session_id', $session->id)->exists())->toBeFalse();
    rtdgNoOrderSent();
});

// ── Robot Trade's own guards still run first, unchanged ─────────────────

test('with the gate on Robot Trade own net-edge fee floor still runs first and still blocks', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    $symbol = 'RTDGEDGEUSDT';
    // Tiny-range candles: the same low-edge shape RobotSessionLiveEvaluationTest uses.
    $rows = [];
    $nowMinuteMs = intdiv((int) floor(microtime(true) * 1000), 60000) * 60000;
    for ($i = 0; $i < 100; $i++) {
        $open = 1950 + ($i * 0.2);
        $close = $open + 0.1;
        $rows[] = [$nowMinuteMs - ((99 - $i) * 60000), (string) $open, (string) ($close + 2), (string) ($open - 2), (string) $close, '100'];
    }
    rtdgFakeBinance($rows);
    $this->partialMock(TradeForecastService::class, function ($mock) {
        $mock->shouldNotReceive('forecast');
    });

    $session = rtdgStartSession($symbol);
    $result = rtdgEvaluateTwice($session);

    expect($result['status'])->toBe('net_edge_block')
        ->and($result['edge']['tradeable'])->toBeFalse();
    rtdgNoOrderSent();
});

test('with the gate on a stale server feed is still reported as stale_feed_block, not as a decision block', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    $symbol = 'RTDGSTALEUSDT';
    rtdgFakeBinance(rtdgKlines('uptrend', true));
    $this->partialMock(TradeForecastService::class, function ($mock) {
        $mock->shouldNotReceive('forecast');
    });

    $session = rtdgStartSession($symbol, 'timed_expiry', false);
    $result = rtdgEvaluateTwice($session);

    expect($result['status'])->toBe('stale_feed_block');
    rtdgNoOrderSent();
});

// ── Gate on: candle_direction ───────────────────────────────────────────

test('with the gate off a live candle-direction robot still opens against an opposite canonical decision (unchanged)', function () {
    $symbol = 'RTDGCDOFFUSDT';
    rtdgFakeBinance(rtdgKlines('downtrend', true));
    rtdgMockCandleBotEntry('BUY');

    $session = rtdgStartSession($symbol, 'candle_direction');
    $result = app(RobotSessionService::class)->evaluateSession($session);

    expect($result['status'])->toBe('opened')
        ->and($result['side'])->toBe('BUY');
    $trade = QuickTrade::where('robot_session_id', $session->id)->firstOrFail();
    expect(array_key_exists('trade_decision_gate', (array) $trade->signal_payload))->toBeFalse();
});

test('with the gate on a live candle-direction BUY is refused when the canonical trade_decision is SELL', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    $symbol = 'RTDGCDSELLUSDT';
    rtdgFakeBinance(rtdgKlines('downtrend', true));
    rtdgMockCandleBotEntry('BUY');

    $session = rtdgStartSession($symbol, 'candle_direction');
    $result = app(RobotSessionService::class)->evaluateSession($session);

    expect($result['status'])->toBe('trade_decision_block')
        ->and($result['side'])->toBe('buy')
        ->and($result['trade_decision_gate']['value'])->toBe('SELL')
        ->and($result['reason'])->toContain('The fee-aware trade decision is SELL, so a live BUY was refused');

    $stored = RobotSession::findOrFail($session->id);
    expect($stored->active_side)->toBeNull();
    expect(QuickTrade::where('robot_session_id', $session->id)->exists())->toBeFalse();
    rtdgNoOrderSent();
});

test('with the gate on a live candle-direction entry opens when the canonical trade_decision agrees', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    $symbol = 'RTDGCDBUYUSDT';
    rtdgFakeBinance(rtdgKlines('uptrend', true));
    rtdgMockCandleBotEntry('BUY');

    $session = rtdgStartSession($symbol, 'candle_direction');
    $result = app(RobotSessionService::class)->evaluateSession($session);

    expect($result['status'])->toBe('opened');
    $trade = QuickTrade::where('robot_session_id', $session->id)->firstOrFail();
    expect(data_get($trade->signal_payload, 'trade_decision_gate.value'))->toBe('BUY')
        // The candle bot's own signal is still the payload, the gate is additive.
        ->and(data_get($trade->signal_payload, 'pattern'))->toBe('body');
});

// ── Scope: workspace override, paper mode ───────────────────────────────

test('a robot_trade settings row turns the gate on for one workspace while the platform default stays off', function () {
    expect(config('robot_trade.block_live_against_decision'))->toBeFalse();
    $symbol = 'RTDGWSUSDT';
    rtdgFakeBinance(rtdgKlines('divergent', true));

    $session = rtdgStartSession($symbol);
    Setting::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $session->workspace_id,
        'group' => 'robot_trade',
        'key' => 'block_live_against_decision',
        'value_json' => true,
        'encrypted' => false,
    ]);

    $result = rtdgEvaluateTwice($session);

    expect($result['status'])->toBe('trade_decision_block');
    rtdgNoOrderSent();
});

test('paper robot sessions are never gated and never compute the canonical decision', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    $this->partialMock(TradeForecastService::class, function ($mock) {
        $mock->shouldNotReceive('forecast');
    });

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    $service = app(RobotSessionService::class);
    $session = $service->create($workspace->id, [
        'symbol' => 'BTCUSDT', 'timeframe' => '1m', 'expiry_seconds' => 60,
        'indicator_set' => ['MACD'], 'base_stake' => 20,
    ], $user->id);
    expect($session->mode)->toBe('paper');

    $gate = new ReflectionMethod(RobotSessionService::class, 'canonicalTradeDecisionGate');
    $result = $gate->invoke($service, $session, rtdgCandles(rtdgKlines('divergent', true)), 'buy', ['action' => 'BUY', 'confidence' => 100]);

    expect($result)->toBe(['block' => null, 'snapshot' => null]);
});

// ── Gate snapshot surfaced for the Robot page ───────────────────────────

test('with the gate on, a blocking gate decision is persisted and served by live-stats and status', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    $service = app(RobotSessionService::class);

    rtdgFakeBinance(rtdgKlines('divergent', true));
    $blocked = rtdgStartSession('RTDGSNAPWAITUSDT');
    rtdgEvaluateTwice($blocked);
    $stored = RobotSession::findOrFail($blocked->id);
    expect(data_get($stored->runtime_json, 'latest_trade_decision_gate.value'))->toBe('WAIT')
        ->and(data_get($stored->runtime_json, 'latest_trade_decision_gate.agrees'))->toBeFalse()
        ->and(data_get($service->liveStats($stored), 'trade_decision_gate.value'))->toBe('WAIT')
        ->and(data_get($service->statusPayload($stored), 'session.runtime_json.latest_trade_decision_gate.value'))->toBe('WAIT');
});

test('with the gate on, a passing gate decision is persisted and served by live-stats', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    $service = app(RobotSessionService::class);

    rtdgFakeBinance(rtdgKlines('uptrend', true));
    $passed = rtdgStartSession('RTDGSNAPBUYUSDT');
    expect(rtdgEvaluateTwice($passed)['status'])->toBe('placed');
    $stored = RobotSession::findOrFail($passed->id);
    expect(data_get($stored->runtime_json, 'latest_trade_decision_gate.value'))->toBe('BUY')
        ->and(data_get($stored->runtime_json, 'latest_trade_decision_gate.agrees'))->toBeTrue()
        ->and(data_get($service->liveStats($stored), 'trade_decision_gate.trade_decision.fee_aware'))->toBeTrue();
});

test('with the gate off nothing is persisted and live-stats reports no gate decision', function () {
    rtdgFakeBinance(rtdgKlines('divergent', true));
    $session = rtdgStartSession('RTDGSNAPOFFUSDT');
    rtdgEvaluateTwice($session);

    $stored = RobotSession::findOrFail($session->id);
    expect(array_key_exists('latest_trade_decision_gate', (array) $stored->runtime_json))->toBeFalse()
        ->and(app(RobotSessionService::class)->liveStats($stored)['trade_decision_gate'])->toBeNull();
});

// ── Candle-count parity with Quick Trade ────────────────────────────────

test('the gate decides on trading_forecast.decision_candle_count candles, the same count Quick Trade placement fetches', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    expect(config('trading_forecast.decision_candle_count'))->toBe(80);
    rtdgFakeBinance(rtdgKlines('divergent', true));

    $result = rtdgEvaluateTwice(rtdgStartSession('RTDGCOUNTUSDT'));

    // The robot still fetches 100 (its own signal/volatility/net-edge inputs
    // are unchanged); only the gate's copy is trimmed to the shared count.
    expect($result['trade_decision_gate']['candles_supplied'])->toBe(80);

    config()->set('trading_forecast.decision_candle_count', 60);
    $result = rtdgEvaluateTwice(rtdgStartSession('RTDGCOUNTSIXTYUSDT'));
    expect($result['trade_decision_gate']['candles_supplied'])->toBe(60);
});

// ── Real account fee in the gate ────────────────────────────────────────

test('with the fee flag on the gate is priced off the account taker rate, which can turn a WAIT into an agreeing BUY', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    config()->set('robot_trade.use_account_fee_rate', true);
    rtdgFakeBinance(rtdgKlines('divergent', true), [
        'symbol' => 'RTDGFEEUSDT', 'makerCommissionRate' => '0.0001', 'takerCommissionRate' => '0.0002',
    ]);

    $session = rtdgStartSession('RTDGFEEUSDT');
    $result = rtdgEvaluateTwice($session);

    // Same 'divergent' market that is WAIT at the config 0.05% taker
    // (required 0.28%): at 0.02% the round trip is 0.04 + 0.04 = 0.08%,
    // required 0.16%, which the ~0.22% expected move clears.
    expect($result['status'])->toBe('placed');
    $gate = data_get(QuickTrade::where('robot_session_id', $session->id)->firstOrFail()->signal_payload, 'trade_decision_gate');
    expect($gate['value'])->toBe('BUY')
        ->and($gate['fee_rate_source'])->toBe('account')
        ->and($gate['trade_decision']['fee_rate_source'])->toBe('account')
        ->and($gate['trade_decision']['cost_pct'])->toEqual(0.08)
        ->and($gate['trade_decision']['required_move_pct'])->toEqual(0.16)
        ->and($gate['account_fee_rate']['taker_rate'])->toEqual(0.0002);
});

test('with the fee flag on a failed lookup keeps the config rate and the gate still blocks', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    config()->set('robot_trade.use_account_fee_rate', true);
    // No commission route: the fake's catch-all returns [] -> malformed.
    rtdgFakeBinance(rtdgKlines('divergent', true));

    $result = rtdgEvaluateTwice(rtdgStartSession('RTDGFEEFAILUSDT'));

    expect($result['status'])->toBe('trade_decision_block')
        ->and($result['trade_decision_gate']['fee_rate_source'])->toBe('config')
        ->and($result['trade_decision_gate']['trade_decision']['required_move_pct'])->toEqual(0.28)
        ->and($result['trade_decision_gate']['account_fee_rate']['reason'])->toBe('malformed_response');
    rtdgNoOrderSent();
});

test('with the fee flag off the gate never calls commissionRate', function () {
    config()->set('robot_trade.block_live_against_decision', true);
    rtdgFakeBinance(rtdgKlines('divergent', true), ['symbol' => 'RTDGFEEOFFUSDT', 'takerCommissionRate' => '0.0002']);

    $result = rtdgEvaluateTwice(rtdgStartSession('RTDGFEEOFFUSDT'));

    expect($result['trade_decision_gate']['fee_rate_source'])->toBe('config')
        ->and($result['trade_decision_gate']['account_fee_rate'])->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fapi/v1/commissionRate'));
});

// ── Robot indicator vote on closed candles (default OFF) ────────────────

test('by default the timed_expiry indicator vote still includes the forming bar (unchanged)', function () {
    expect(config('robot_trade.indicator_vote_closed_candles_only'))->toBeFalse();
    rtdgFakeBinance(rtdgKlines('forming_drop', true));

    $session = rtdgStartSession('RTDGVOTEOFFUSDT');
    app(RobotSessionService::class)->evaluateSession($session);

    $signal = data_get(RobotSession::findOrFail($session->id)->runtime_json, 'latest_signal');
    expect($signal['direction'])->toBe('sell')
        ->and(array_key_exists('vote_input', $signal))->toBeFalse();
});

test('with robot_trade.indicator_vote_closed_candles_only on the vote ignores the forming bar', function () {
    config()->set('robot_trade.indicator_vote_closed_candles_only', true);
    rtdgFakeBinance(rtdgKlines('forming_drop', true));

    $session = rtdgStartSession('RTDGVOTEONUSDT');
    app(RobotSessionService::class)->evaluateSession($session);

    $signal = data_get(RobotSession::findOrFail($session->id)->runtime_json, 'latest_signal');
    expect($signal['direction'])->toBe('buy')
        ->and($signal['vote_input'])->toBe(['closed_candles_only' => true, 'candles_used' => 99, 'candles_fetched' => 100]);
});
