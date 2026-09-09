<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\MultiWindowTrendScoringService;
use App\Domain\RatioBot\RatioBotDecisionService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Domain\RatioBot\WalletAwareDirectionFallbackService;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\RatioBotBrainSnapshot;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RatioBotForecastBacktest;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * WalletAwareDirectionFallback -- resolveAutoStartDirection() used to stay
 * idle forever when the brain/trend-resolved direction was blocked purely
 * by insufficient wallet balance, even if the OPPOSITE side had both ample
 * balance AND its own independent market justification. This service (and
 * its wiring into RunRatioBotEvaluationJob::resolveAutoStartDirection())
 * lets the bot arm the opposite direction instead, but ONLY for that
 * specific wallet-insufficiency block -- confidence blocks and trend/
 * forecast-disagreement blocks are completely untouched.
 */
class WalletAwareDirectionFallbackTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -- Direct service-level tests ---------------------------------------

    public function test_single_tick_alignment_starts_the_streak_without_flipping(): void
    {
        Carbon::setTestNow('2026-09-09 00:00:00');
        $config = $this->makeBot(['wallet_fallback_enabled' => true]);
        $state = $this->makeState($config);

        $result = app(WalletAwareDirectionFallbackService::class)->evaluate(
            $config,
            $state,
            blockedDirection: 'SELL',
            trendScore: ['direction' => 'bearish', 'composite_score' => -0.4],
            hasBase: false,
            hasQuote: true,
        );

        $this->assertSame('observing', $result['action']);
        $this->assertTrue($result['streak_started']);

        $state->refresh();
        $this->assertNotNull($state->wallet_fallback_streak_started_at);
        $this->assertSame(1, $state->wallet_fallback_streak_ticks);
    }

    public function test_streak_resets_to_zero_after_any_tick_where_either_condition_drops(): void
    {
        Carbon::setTestNow('2026-09-09 00:00:00');
        $config = $this->makeBot([
            'wallet_fallback_enabled' => true,
            'wallet_fallback_base_observation_minutes' => 1000,
            'wallet_fallback_min_observation_minutes' => 1,
            'wallet_fallback_max_observation_minutes' => 2000,
        ]);
        $state = $this->makeState($config);
        $service = app(WalletAwareDirectionFallbackService::class);

        // Three qualifying ticks in a row build up a streak.
        for ($i = 0; $i < 3; $i++) {
            Carbon::setTestNow(now()->addMinutes(5));
            $result = $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);
            $this->assertSame('observing', $result['action']);
        }
        $state->refresh();
        $this->assertNotNull($state->wallet_fallback_streak_started_at);
        $this->assertGreaterThan(0, $state->wallet_fallback_streak_ticks);

        // Balance drops away on the 4th tick -- streak must reset to zero,
        // not merely stop growing.
        Carbon::setTestNow(now()->addMinutes(5));
        $result = $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, false);

        $this->assertSame('none', $result['action']);
        $state->refresh();
        $this->assertNull($state->wallet_fallback_streak_started_at);
        $this->assertSame(0, $state->wallet_fallback_streak_ticks);
    }

    public function test_streak_resets_to_zero_when_trend_direction_drops_even_with_balance_present(): void
    {
        Carbon::setTestNow('2026-09-09 00:00:00');
        $config = $this->makeBot([
            'wallet_fallback_enabled' => true,
            'wallet_fallback_base_observation_minutes' => 1000,
        ]);
        $state = $this->makeState($config);
        $service = app(WalletAwareDirectionFallbackService::class);

        Carbon::setTestNow(now()->addMinutes(5));
        $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);
        Carbon::setTestNow(now()->addMinutes(5));
        $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);
        $state->refresh();
        $this->assertGreaterThan(0, $state->wallet_fallback_streak_ticks);

        // Trend flips to neutral -- balance still present, but no longer
        // independently justified.
        Carbon::setTestNow(now()->addMinutes(5));
        $result = $service->evaluate($config, $state, 'SELL', ['direction' => 'neutral', 'composite_score' => 0.0], false, true);

        $this->assertSame('none', $result['action']);
        $state->refresh();
        $this->assertNull($state->wallet_fallback_streak_started_at);
        $this->assertSame(0, $state->wallet_fallback_streak_ticks);
    }

    public function test_a_fast_config_flips_once_its_own_shorter_computed_window_elapses(): void
    {
        Carbon::setTestNow('2026-09-09 00:00:00');
        $config = $this->makeBot([
            'wallet_fallback_enabled' => true,
            'wallet_fallback_base_observation_minutes' => 5,
            'wallet_fallback_min_observation_minutes' => 1,
            'wallet_fallback_max_observation_minutes' => 10,
        ]);
        $state = $this->makeState($config);
        $service = app(WalletAwareDirectionFallbackService::class);

        $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);

        // 6 minutes later: with no prior composite the adjustment is neutral
        // (1.0x), so required minutes == base == 5 -> 6 >= 5 satisfies it.
        Carbon::setTestNow(now()->addMinutes(6));
        $result = $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);

        $this->assertSame('flip', $result['action']);
        $this->assertSame('BUY', $result['new_direction']);
    }

    public function test_a_slow_config_does_not_flip_at_the_same_elapsed_time_the_fast_config_did(): void
    {
        Carbon::setTestNow('2026-09-09 00:00:00');
        $config = $this->makeBot([
            'wallet_fallback_enabled' => true,
            'wallet_fallback_base_observation_minutes' => 30,
            'wallet_fallback_min_observation_minutes' => 1,
            'wallet_fallback_max_observation_minutes' => 60,
        ]);
        $state = $this->makeState($config);
        $service = app(WalletAwareDirectionFallbackService::class);

        $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);

        // Same 6-minute gap the fast config flipped on -- this bot's own
        // computed window (30 minutes, not a hardcoded constant) is nowhere
        // near satisfied yet.
        Carbon::setTestNow(now()->addMinutes(6));
        $result = $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);
        $this->assertSame('observing', $result['action']);

        // 31 minutes after the streak started -- now it does.
        Carbon::setTestNow(now()->addMinutes(25));
        $result = $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);
        $this->assertSame('flip', $result['action']);
    }

    public function test_cooldown_blocks_a_second_flip_until_the_configured_minutes_elapse(): void
    {
        Carbon::setTestNow('2026-09-09 00:00:00');
        $config = $this->makeBot([
            'wallet_fallback_enabled' => true,
            'wallet_fallback_base_observation_minutes' => 5,
            'wallet_fallback_min_observation_minutes' => 1,
            'wallet_fallback_max_observation_minutes' => 10,
            'wallet_fallback_min_cooldown_minutes' => 60,
        ]);
        $state = $this->makeState($config);
        $service = app(WalletAwareDirectionFallbackService::class);

        // First flip.
        $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);
        Carbon::setTestNow(now()->addMinutes(6));
        $first = $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);
        $this->assertSame('flip', $first['action']);

        $state->refresh();
        $this->assertNotNull($state->last_wallet_fallback_flip_at);

        // Conditions immediately qualify again and the observation window is
        // exhausted again, but the cooldown (60 min) has not elapsed.
        Carbon::setTestNow(now()->addMinute());
        $second = $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);
        $this->assertSame('observing', $second['action']); // starts a fresh streak

        Carbon::setTestNow(now()->addMinutes(6));
        $third = $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);
        $this->assertSame('cooldown_blocked', $third['action']);

        // Streak state is NOT reset by a cooldown block.
        $state->refresh();
        $this->assertNotNull($state->wallet_fallback_streak_started_at);

        // Once the full 60 minutes since the first flip has elapsed, the
        // still-qualifying streak succeeds.
        Carbon::setTestNow(Carbon::parse('2026-09-09 01:07:00'));
        $fourth = $service->evaluate($config, $state, 'SELL', ['direction' => 'bearish', 'composite_score' => -0.4], false, true);
        $this->assertSame('flip', $fourth['action']);
    }

    public function test_wallet_balance_present_but_trend_direction_not_opposite_never_flips(): void
    {
        Carbon::setTestNow('2026-09-09 00:00:00');
        $config = $this->makeBot([
            'wallet_fallback_enabled' => true,
            'wallet_fallback_base_observation_minutes' => 1,
            'wallet_fallback_min_observation_minutes' => 1,
        ]);
        $state = $this->makeState($config);
        $service = app(WalletAwareDirectionFallbackService::class);

        // Balance is present for the opposite side, but the trend never
        // agrees with it (it's 'bullish', not the 'bearish' the opposite BUY
        // side requires when SELL is blocked) -- must never flip, no matter
        // how many ticks pass.
        for ($i = 0; $i < 10; $i++) {
            Carbon::setTestNow(now()->addHours(2));
            $result = $service->evaluate($config, $state, 'SELL', ['direction' => 'bullish', 'composite_score' => 0.9], false, true);
            $this->assertSame('none', $result['action']);
            $this->assertFalse($result['independently_justified']);
        }

        $state->refresh();
        $this->assertNull($state->wallet_fallback_streak_started_at);
        $this->assertSame(0, $state->wallet_fallback_streak_ticks);
    }

    public function test_disabled_config_returns_disabled_action_and_touches_no_state(): void
    {
        $config = $this->makeBot(['wallet_fallback_enabled' => false]);
        $state = $this->makeState($config);

        $result = app(WalletAwareDirectionFallbackService::class)->evaluate(
            $config,
            $state,
            'SELL',
            ['direction' => 'bearish', 'composite_score' => -0.4],
            false,
            true,
        );

        $this->assertSame('disabled', $result['action']);
        $state->refresh();
        $this->assertNull($state->wallet_fallback_streak_started_at);
        $this->assertSame(0, $state->wallet_fallback_streak_ticks);
    }

    public function test_a_flip_emits_a_ratio_bot_event_with_the_expected_shape(): void
    {
        Carbon::setTestNow('2026-09-09 00:00:00');
        $config = $this->makeBot([
            'wallet_fallback_enabled' => true,
            'wallet_fallback_base_observation_minutes' => 5,
            'wallet_fallback_min_observation_minutes' => 1,
            'wallet_fallback_max_observation_minutes' => 10,
        ]);
        $state = $this->makeState($config);
        $service = app(WalletAwareDirectionFallbackService::class);

        $service->evaluate($config, $state, 'BUY', ['direction' => 'bullish', 'composite_score' => 0.5], true, false);
        Carbon::setTestNow(now()->addMinutes(6));
        $result = $service->evaluate($config, $state, 'BUY', ['direction' => 'bullish', 'composite_score' => 0.5], true, false);

        $this->assertSame('flip', $result['action']);
        $this->assertSame('SELL', $result['new_direction']);

        $event = RatioBotEvent::where('ratio_bot_config_id', $config->id)
            ->where('event_type', RatioBotEvent::EVENT_WALLET_FALLBACK_FLIP)
            ->latest('occurred_at')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('BUY', $event->context['blocked_direction']);
        $this->assertSame('SELL', $event->context['to_direction']);
    }

    // -- Wiring into RunRatioBotEvaluationJob::resolveAutoStartDirection() --

    public function test_wallet_fallback_disabled_regression_guard_stays_idle_with_the_pre_existing_reason(): void
    {
        $config = $this->makeBot([
            'execution_mode' => 'live',
            'brain_enabled' => true,
            'wallet_fallback_enabled' => false,
            'strategy_params' => [
                'auto_start_direction_mode' => 'auto',
                'auto_start_min_confidence' => 60,
            ],
        ]);
        $this->mockTrendScore(['direction' => 'bullish', 'composite_score' => 0.5]);
        $this->seedBrain($config, 'bullish', 80);

        $this->mock(WalletAwareDirectionFallbackService::class, function ($mock): void {
            $mock->shouldNotReceive('evaluate');
        });

        $direction = $this->invokeResolveAutoStartDirection($config, 2000.0, false, true);

        $this->assertNull($direction);
        $event = RatioBotEvent::where('ratio_bot_config_id', $config->id)->latest('occurred_at')->first();
        $this->assertSame('bullish auto-start needs base-asset balance for SELL-watch', $event->context['reason']);
        $this->assertNull($event->context['wallet_fallback']);
    }

    public function test_confidence_block_never_invokes_the_fallback_service(): void
    {
        $config = $this->makeBot([
            'execution_mode' => 'live',
            'brain_enabled' => true,
            'wallet_fallback_enabled' => true,
            'strategy_params' => [
                'auto_start_direction_mode' => 'auto',
                'auto_start_min_confidence' => 90,
            ],
        ]);
        $this->seedBrain($config, 'bullish', 50); // below the 90 bar

        $this->mock(WalletAwareDirectionFallbackService::class, function ($mock): void {
            $mock->shouldNotReceive('evaluate');
        });

        $direction = $this->invokeResolveAutoStartDirection($config, 2000.0, false, false);

        $this->assertNull($direction);
        $event = RatioBotEvent::where('ratio_bot_config_id', $config->id)->latest('occurred_at')->first();
        $this->assertStringContainsString('confidence 50 below required 90', $event->context['reason']);
    }

    public function test_trend_forecast_disagreement_block_never_invokes_the_fallback_service(): void
    {
        // legacy_single_window mode -- trendScore is null, so the wiring's
        // own trendScore !== null guard skips the fallback before it can
        // even be reached, exactly like a hard disagreement veto today.
        $config = $this->makeBot([
            'execution_mode' => 'live',
            'brain_enabled' => true,
            'wallet_fallback_enabled' => true,
            'strategy_params' => [
                'auto_start_direction_mode' => 'auto',
                'auto_start_min_confidence' => 60,
                'auto_start_observation_candles' => 12,
                'trend_scoring_mode' => 'legacy_single_window',
            ],
        ]);
        $this->seedTrend($config, 2100.0, -5.0, 12); // bearish candles
        $this->seedBrain($config, 'bullish', 80); // disagreeing forecast

        $this->mock(WalletAwareDirectionFallbackService::class, function ($mock): void {
            $mock->shouldNotReceive('evaluate');
        });

        $direction = $this->invokeResolveAutoStartDirection($config, 2000.0, false, true);

        $this->assertNull($direction);
        $event = RatioBotEvent::where('ratio_bot_config_id', $config->id)->latest('occurred_at')->first();
        $this->assertStringContainsString('does not match forecast', $event->context['reason']);
    }

    public function test_full_wiring_flips_the_resolved_direction_once_the_window_and_wallet_both_hold(): void
    {
        Carbon::setTestNow('2026-09-09 00:00:00');
        $config = $this->makeBot([
            'execution_mode' => 'live',
            'brain_enabled' => true,
            'wallet_fallback_enabled' => true,
            'wallet_fallback_base_observation_minutes' => 10,
            'wallet_fallback_min_observation_minutes' => 1,
            'wallet_fallback_max_observation_minutes' => 20,
            'wallet_fallback_min_cooldown_minutes' => 60,
            'strategy_params' => [
                'auto_start_direction_mode' => 'auto',
                'auto_start_min_confidence' => 60,
            ],
        ]);

        // Raw trend disagrees with the brain forecast (bearish vs bullish);
        // a high-reliability backtest makes the reliability-weighted
        // resolution side with the forecast (bullish) -- see
        // AnchorDirectionReliabilityWeightingTest for the exact math this
        // mirrors. Resolved direction 'bullish' wants SELL-watch, blocked by
        // no base balance -- but the RAW trend ('bearish') independently
        // justifies the opposite (BUY), and quote balance is present.
        //
        // The brain service is mocked (not seeded via a real snapshot) so
        // the forecast stays fixed across the 11-minute time jump below --
        // RatioBotBrainService::resolve() would otherwise treat the seeded
        // snapshot as stale past its horizon and try to recompute one from
        // real candle data this test never seeds, returning null and
        // collapsing the scenario to an unrelated "forecast is neutral"
        // block instead of the wallet-insufficiency block under test.
        $this->mockTrendScore(['direction' => 'bearish', 'composite_score' => -0.2]);
        $this->seedBacktest($config, 85.0);
        $this->mock(\App\Domain\RatioBot\RatioBotBrainService::class, function ($mock) use ($config): void {
            $snapshot = new RatioBotBrainSnapshot([
                'forecast_bias' => 'bullish',
                'direction' => 'bullish',
                'forecast_confidence' => 80,
                'confidence' => 80,
            ]);
            $mock->shouldReceive('resolve')->with($config, \Mockery::any())->andReturn($snapshot);
            $mock->shouldReceive('horizonSeconds')->with($config)->andReturn(60);
        });

        // Tick 1: wallet-blocked, streak starts, still idle.
        $direction1 = $this->invokeResolveAutoStartDirection($config, 2000.0, false, true);
        $this->assertNull($direction1);

        // Tick 2, 11 minutes later: window (10 min, neutral 1x adjustment)
        // elapsed -> flips to the opposite, wallet-funded, independently
        // justified direction.
        Carbon::setTestNow(now()->addMinutes(11));
        $direction2 = $this->invokeResolveAutoStartDirection($config, 2000.0, false, true);
        $this->assertSame('BUY', $direction2);

        $event = RatioBotEvent::where('ratio_bot_config_id', $config->id)
            ->where('event_type', 'auto_start_direction_selected')
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('flip', $event->context['wallet_fallback']['action']);
    }

    public function test_after_a_fallback_produced_anchor_the_normal_reversal_wait_still_gates_execution(): void
    {
        // Simulates the exact anchor the wiring performs when
        // resolveAutoStartDirection() returns 'BUY' from a flip (same
        // orderId, same anchorManualSell call the existing non-fallback auto-
        // direction path already uses at ~line 319-320) -- then proves the
        // ordinary trailing reversal-wait mechanics still gate the real BUY,
        // exactly as they do for a normal (non-fallback) anchor.
        $config = $this->makeBot([
            'execution_mode' => 'sandbox',
            'algorithm' => 'trailing_ratio',
            'buy_threshold_ratio' => 0.02,
            'buying_loss_ratio' => 1.0,
            'fee_bps' => 0,
            'max_slippage_bps' => 0,
        ]);
        $state = $this->makeState($config);
        $config->setRelation('state', $state);

        app(RatioBotStateService::class)->anchorManualSell(
            config: $config,
            sellPrice: 100.0,
            quantity: 10.0,
            orderId: 'auto-direction-anchor',
        );

        // Price drops 3% -- past the 2% trigger boundary, arming the buy
        // watch, but with no reversal/rebound yet.
        $result = app(RatioBotDecisionService::class)->evaluate($config->fresh('state'), 97.0);

        $this->assertSame('HOLD', $result['decision']);
        $state = $config->fresh('state')->state;
        $this->assertTrue((bool) $state->buy_armed);
        $this->assertSame(
            0,
            \App\Models\RatioBotDecision::where('ratio_bot_config_id', $config->id)
                ->where('decision', 'BUY')
                ->count(),
        );
    }

    // -- Helpers ------------------------------------------------------------

    private function invokeResolveAutoStartDirection(RatioBotConfig $config, float $currentPrice, bool $hasBase, bool $hasQuote): ?string
    {
        $job = new RunRatioBotEvaluationJob((string) $config->id);
        $method = new ReflectionMethod(RunRatioBotEvaluationJob::class, 'resolveAutoStartDirection');
        $method->setAccessible(true);

        return $method->invoke($job, $config, $currentPrice, $hasBase, $hasQuote);
    }

    private function mockTrendScore(array $result): void
    {
        $this->mock(MultiWindowTrendScoringService::class, function ($mock) use ($result): void {
            $mock->shouldReceive('score')->andReturn($result);
        });
    }

    private function seedBacktest(RatioBotConfig $config, float $hitRatePct, int $horizonSeconds = 60): void
    {
        RatioBotForecastBacktest::create([
            'ratio_bot_config_id' => $config->id,
            'horizon_seconds' => $horizonSeconds,
            'sample_size' => 100,
            'hits' => (int) round($hitRatePct),
            'hit_rate_pct' => $hitRatePct,
            'avg_confidence_pct' => 70.0,
            'computed_at' => now(),
        ]);
    }

    private function seedBrain(RatioBotConfig $config, string $direction, int $confidence): void
    {
        RatioBotBrainSnapshot::create([
            'ratio_bot_config_id' => $config->id,
            'symbol' => $config->normalizedSymbol(),
            'timeframe' => $config->timeFrame(),
            'horizon_seconds' => 60,
            'price_at_evaluation' => 2000,
            'direction' => $direction,
            'confidence' => $confidence,
            'bullish_score' => $direction === 'bullish' ? 80 : 10,
            'neutral_score' => 10,
            'bearish_score' => $direction === 'bearish' ? 80 : 10,
            'reasons' => [],
            'forecast_bias' => $direction,
            'forecast_confidence' => $confidence,
            'expected_mid_price' => $direction === 'bullish' ? 2020 : 1980,
            'expected_low' => 1975,
            'expected_high' => 2025,
            'atr_pct' => 0.01,
            'raw_payload' => [],
        ]);
    }

    private function seedTrend(RatioBotConfig $config, float $start, float $step, int $count): void
    {
        $time = now()->subMinutes($count);
        for ($i = 0; $i < $count; $i++) {
            $open = $start + ($step * $i);
            $close = $open + $step;
            \App\Models\MarketOhlc::create([
                'exchange' => $config->exchangeKey(),
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => $config->timeFrame(),
                'open' => $open,
                'high' => max($open, $close) + 1,
                'low' => min($open, $close) - 1,
                'close' => $close,
                'volume' => 10,
                'ts' => $time->copy()->addMinutes($i),
            ]);
        }
    }

    private function makeBot(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'timeframe' => '1m',
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
        ], $overrides));

        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_IDLE,
            'feed_status' => 'healthy',
        ]);
        $config->setRelation('state', $state);

        return $config;
    }

    private function makeState(RatioBotConfig $config): RatioBotState
    {
        return $config->state ?? RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_IDLE,
        ]);
    }
}
