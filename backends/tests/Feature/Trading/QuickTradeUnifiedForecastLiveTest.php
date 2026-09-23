<?php

namespace Tests\Feature\Trading;

use App\Domain\MarketIntelligence\UnifiedForecastEngine;
use App\Domain\Ops\FeatureFlagService;
use App\Domain\Trading\TradeForecastService;
use App\Models\MarketOhlc;
use App\Models\QuickTrade;
use App\Models\RatioBotForecastBacktest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Unified Forecast Engine, Phase 6 -- Quick Trade LIVE decision wiring
 * (`unified_forecast.quick_trade_live`, default OFF). Proves the
 * triple-fail-closed priority order:
 *   1. Flag off -> byte-identical to internal-only behavior.
 *   2. Flag on, scope not VALIDATED -> falls back to internal.
 *   3. Flag on, scope VALIDATED -> real engine direction/confidence used.
 *   4. Engine throws -> falls back to internal, never crashes.
 *   5. A stale-data/low-volume gate inside forecast() itself always wins,
 *      regardless of which engine would have said BUY/SELL.
 */
class QuickTradeUnifiedForecastLiveTest extends TestCase
{
    use RefreshDatabase;

    private function candles(): array
    {
        $candles = [];
        $price = 2000.0;
        for ($i = 0; $i < 20; $i++) {
            $open = $price;
            $close = $open + 2;
            $candles[] = [
                'open' => $open,
                'high' => $close + 1,
                'low' => $open - 1,
                'close' => $close,
                'volume' => 50,
            ];
            $price = $close;
        }

        return $candles;
    }

    private function payload(string $symbol = 'FLIVEUSDT', array $overrides = []): array
    {
        return array_merge([
            'candles' => $this->candles(),
            'timeframe' => '1m',
            'expiry_seconds' => 60,
            'stake_amount' => 10,
            'symbol' => $symbol,
            'exchange' => 'binance',
        ], $overrides);
    }

    private function seedUptrend(string $symbol, string $timeframe = '1m', int $count = 60): void
    {
        $time = now()->subMinutes($count + 1);
        $price = 2000.0;
        for ($i = 0; $i < $count; $i++) {
            $open = $price;
            $close = $open + 5.0;
            MarketOhlc::create([
                'exchange' => 'binance', 'symbol' => $symbol, 'timeframe' => $timeframe,
                'open' => $open, 'high' => $close + 1, 'low' => $open - 1,
                'close' => $close, 'volume' => 10, 'ts' => $time->copy()->addMinutes($i),
            ]);
            $price = $close;
        }
    }

    /** Seeds every ForecastModelPromotionGate gate a VALIDATED scope needs. */
    private function seedValidatedScope(string $botType, string $botInstanceId, int $horizonSeconds): void
    {
        foreach (['training', 'holdout'] as $period) {
            RatioBotForecastBacktest::create([
                'bot_type' => $botType,
                'bot_instance_id' => $botInstanceId,
                'horizon_seconds' => $horizonSeconds,
                'confidence_bucket' => '80-100',
                'evaluation_period' => $period,
                'sample_size' => 50,
                'hits' => 40,
                'hit_rate_pct' => 80.0,
                'avg_confidence_pct' => 85.0,
                'computed_at' => now(),
            ]);
        }
    }

    /** Real, settled, execution_environment==='live' winning trades -- what
     * ForecastLiveEvidenceService::quickTradeHoldoutPnlSummary() reads. */
    private function seedWinningLiveTrades(string $symbol, string $timeframe, int $horizonSeconds, int $count = 30, ?string $robotSessionId = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            QuickTrade::create([
                'workspace_id' => \App\Models\Workspace::factory()->create()->id,
                'robot_session_id' => $robotSessionId,
                'symbol' => $symbol,
                'side' => 'buy',
                'stake_amount' => 100,
                'quantity' => 1,
                'asset_class' => 'CRYPTO',
                'mode' => 'live',
                'source' => $robotSessionId !== null ? 'robot_trade' : 'manual_quick_trade',
                'provider_key' => 'binance',
                'quote_currency' => 'USDT',
                'timeframe' => $timeframe,
                'expiry_seconds' => $horizonSeconds,
                'payout_ratio' => 0,
                'entry_price' => 100,
                'exit_price' => 101,
                'profit_loss' => 5.0,
                'gross_pnl' => 5.5,
                'fees_total' => 0.5,
                'fee_source' => 'real',
                'status' => 'won',
                'entry_at' => now()->subMinutes(10 + $i),
                'expires_at' => now()->subMinutes(9 + $i),
                'settled_at' => now()->subMinutes(9 + $i),
                'signal_payload' => [
                    'direction' => 'buy',
                    'research' => ['execution_environment' => 'live'],
                ],
            ]);
        }
    }

    // ── 1. Flag off: byte-identical to internal-only behavior ──────────────

    public function test_flag_off_is_byte_identical_to_internal_only_forecast(): void
    {
        $user = User::factory()->create();
        $this->travelTo(now()->startOfSecond());
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_live', false);
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', false);

        $payload = $this->payload();
        $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $payload);
        $response->assertOk();

        $direct = app(TradeForecastService::class)->forecast(
            $payload['candles'], $payload['timeframe'], $payload['expiry_seconds'], null, null, null, $payload['symbol']
        );
        $direct['decision_source'] = 'internal';
        // Round-trip through JSON exactly like the real HTTP response does,
        // so a whole-number float (e.g. 2040.0) compares equal to its JSON
        // decode (2040) -- a JSON encoding artifact, not a real difference.
        $direct = json_decode(json_encode($direct), true);

        $actual = $response->json('forecast');

        // This test's real subject is the UNIFIED FORECAST FLAG: with it off,
        // every field TradeForecastService itself produces must be identical
        // to calling that service directly. It is deliberately NOT an
        // assertion that the HTTP endpoint adds no fields of its own --
        // QuickTradeForecastCalculator has always wrapped the service with
        // presentation/telemetry fields the service cannot know about
        // (`live_feed` is the long-standing example; Part B/C added the
        // candle-timing, cost-model and duration-availability fields beside
        // it). Those extras are enumerated explicitly below so a genuinely
        // unexpected new field still fails this test.
        $calculatorAdditiveFields = [
            'live_feed',
            'candle_timing',
            'cost_model',
            'decision_cache',
            'duration_availability',
            'shortest_viable_expiry_seconds',
        ];

        // Part B3 fields are null when forecast() is called WITHOUT candle
        // timing (as this test's direct call deliberately is) and populated
        // when Quick Trade's own calculator supplies it. That difference is
        // the feature, not a flag effect, so they are compared for presence
        // rather than for equality.
        $candleTimingFields = [
            'input_candles_include_forming_bar',
            'decision_candle_close_at',
            'seconds_since_last_close',
            'next_close_in_seconds',
        ];

        foreach ($direct as $key => $value) {
            $this->assertArrayHasKey($key, $actual, "Forecast field '{$key}' missing from the HTTP response.");
            if (in_array($key, $candleTimingFields, true)) {
                continue;
            }
            $this->assertSame($value, $actual[$key], "Forecast field '{$key}' differs with the unified-forecast flag off.");
        }

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($actual), array_keys($direct), $calculatorAdditiveFields)),
            'The forecast endpoint returned a field that neither TradeForecastService nor the known calculator wrapper produces.',
        );
    }

    public function test_flag_off_never_calls_the_unified_forecast_engine(): void
    {
        $user = User::factory()->create();
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_live', false);
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', false);

        $spy = Mockery::mock(UnifiedForecastEngine::class);
        $spy->shouldNotReceive('evaluate');
        $this->app->instance(UnifiedForecastEngine::class, $spy);

        $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $this->payload());
        $response->assertOk();
        $this->assertSame('internal', $response->json('forecast.decision_source'));
    }

    // ── 2. Flag on, scope NOT validated -> falls back to internal ─────────

    public function test_flag_on_but_scope_not_validated_falls_back_to_internal(): void
    {
        $user = User::factory()->create();
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_live', true);
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', false);

        Log::spy();

        $payload = $this->payload('NOTVALUSDT');
        $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $payload);
        $response->assertOk();

        $this->assertSame('internal', $response->json('forecast.decision_source'));
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message) => str_contains($message, 'live mode enabled but scope not validated'))
            ->once();
    }

    // ── 3. Flag on, scope VALIDATED -> real engine result used ────────────

    public function test_flag_on_and_scope_validated_uses_the_real_engine_direction_and_confidence(): void
    {
        $user = User::factory()->create();
        $symbol = 'VALIDUSDT';
        $this->seedUptrend($symbol);
        $botInstanceId = "binance:{$symbol}:1m";
        $this->seedValidatedScope('quick_trade', $botInstanceId, 60);
        $this->seedWinningLiveTrades($symbol, '1m', 60);

        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_live', true);
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', false);

        $engineResult = app(UnifiedForecastEngine::class)->evaluate('quick_trade', $botInstanceId, 'binance', $symbol, '1m', 60);
        $this->assertSame('clear', $engineResult['status'], 'test fixture must actually produce a clear engine direction');
        $this->assertSame('BUY', $engineResult['direction']);

        $payload = $this->payload($symbol);
        $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $payload);
        $response->assertOk();

        $this->assertSame('unified_forecast_engine', $response->json('forecast.decision_source'));
        $this->assertSame('BUY', $response->json('forecast.decision_trend'));
        $this->assertEqualsWithDelta((float) $engineResult['confidence'], (float) $response->json('forecast.confidence'), 0.0001);
        $this->assertIsArray($response->json('forecast.unified_forecast_decision'));
    }

    public function test_flag_on_and_validated_reuses_the_shadow_computed_engine_result_instead_of_calling_evaluate_twice(): void
    {
        $user = User::factory()->create();
        $symbol = 'REUSEUSDT';
        $this->seedUptrend($symbol);
        $botInstanceId = "binance:{$symbol}:1m";
        $this->seedValidatedScope('quick_trade', $botInstanceId, 60);
        $this->seedWinningLiveTrades($symbol, '1m', 60);

        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_live', true);
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', true);

        $realEngine = app(UnifiedForecastEngine::class);
        $spy = Mockery::mock($realEngine)->makePartial();
        $spy->shouldReceive('evaluate')->once()->andReturnUsing(fn (...$args) => $realEngine->evaluate(...$args));
        $this->app->instance(UnifiedForecastEngine::class, $spy);

        $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $this->payload($symbol));
        $response->assertOk();
        $this->assertSame('unified_forecast_engine', $response->json('forecast.decision_source'));
    }

    // ── 4. Engine throws -> falls back to internal, never crashes ─────────

    public function test_engine_exception_falls_back_to_internal_without_crashing(): void
    {
        $user = User::factory()->create();
        $symbol = 'THROWUSDT';
        $this->seedUptrend($symbol);
        $botInstanceId = "binance:{$symbol}:1m";
        $this->seedValidatedScope('quick_trade', $botInstanceId, 60);
        $this->seedWinningLiveTrades($symbol, '1m', 60);

        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_live', true);
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', false);

        $spy = Mockery::mock(UnifiedForecastEngine::class);
        $spy->shouldReceive('evaluate')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(UnifiedForecastEngine::class, $spy);

        Log::spy();

        $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $this->payload($symbol));
        $response->assertOk();

        $this->assertSame('internal', $response->json('forecast.decision_source'));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'engine evaluate() failed'))
            ->once();
    }

    // ── 5. Stale-data gate always wins, regardless of engine ───────────────

    public function test_stale_data_gate_forces_wait_even_when_scope_is_validated(): void
    {
        $user = User::factory()->create();
        $symbol = 'STALEUSDT';
        $this->seedUptrend($symbol);
        $botInstanceId = "binance:{$symbol}:1m";
        $this->seedValidatedScope('quick_trade', $botInstanceId, 60);
        $this->seedWinningLiveTrades($symbol, '1m', 60);

        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_live', true);
        app(FeatureFlagService::class)->set('unified_forecast.quick_trade_shadow', false);

        $priceCacheMock = Mockery::mock(\App\Domain\Market\MarketPriceCacheService::class)->makePartial();
        $priceCacheMock->shouldReceive('getLiveFeedSnapshot')
            ->andReturn(['fresh' => false, 'age_seconds' => 45, 'price' => 2000.0]);
        $this->app->instance(\App\Domain\Market\MarketPriceCacheService::class, $priceCacheMock);

        $spy = Mockery::mock(UnifiedForecastEngine::class);
        $spy->shouldNotReceive('evaluate');
        $this->app->instance(UnifiedForecastEngine::class, $spy);

        $response = $this->actingAs($user)->postJson('/api/v1/quick-trade/forecast', $this->payload($symbol));
        $response->assertOk();

        $this->assertSame('WAIT', $response->json('forecast.decision_trend'));
        $this->assertSame('stale_data', $response->json('forecast.gate_reason'));
        $this->assertSame('internal', $response->json('forecast.decision_source'));
    }
}
