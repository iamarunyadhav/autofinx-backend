<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotRuntimeQueryService;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\MarketOhlc;
use App\Models\RatioBotBrainSnapshot;
use App\Models\RatioBotConfig;
use App\Models\RatioBotForecastBacktest;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Fix: RunRatioBotEvaluationJob::resolveAutoStartDirection()'s trend/forecast
 * mismatch branch used to be a rigid, unconditional AND-gate veto -- a
 * forecast that has never been measured, or measured no-better-than-a-coin-
 * flip, could block an independently-qualified trend signal from ever
 * starting a bot. This is replaced with a winner-take-all resolution
 * (RunRatioBotEvaluationJob::resolveDirectionWithReliabilityWeighting):
 * the forecast's contribution is its own measured (walk-forward backtested)
 * reliability weight for this bot's horizon -- see
 * ForecastHorizonBacktestService / RatioBotForecastBacktest -- and the
 * trend's contribution is the magnitude of its own composite score times
 * the complementary weight. Whichever is larger wins outright.
 *
 * Real production evidence that motivated this fix: a BNBUSDT forecast
 * backtest measured hit_rate_pct 45.95 (worse than a coin flip) yet the old
 * gate blocked a composite_score 1.0 (max possible, full window agreement)
 * bullish trend purely because the forecast said bearish.
 */
class AnchorDirectionReliabilityWeightingTest extends TestCase
{
    use RefreshDatabase;

    public function test_regression_an_unproven_low_hit_rate_forecast_no_longer_vetoes_a_strong_qualifying_trend(): void
    {
        $config = $this->makeBot([
            'execution_mode' => 'live',
            'brain_enabled' => true,
            'strategy_params' => [
                'auto_start_direction_mode' => 'auto',
                'auto_start_min_confidence' => 60,
                'auto_start_observation_candles' => 12,
            ],
        ]);
        // Strong, consistent uptrend -> multi-window composite clearly
        // bullish and non-zero across all three windows.
        $this->seedTrend($config, 1900.0, 8.0, 48);
        // Forecast disagrees (bearish) and would have met the confidence bar.
        $this->seedBrain($config, 'bearish', 80);
        // The exact real-world reading cited in the fix: worse than a coin
        // flip (<=50 -> reliability weight 0.0).
        $this->seedBacktest($config, 45.95);

        $this->mock(RatioBotRuntimeQueryService::class, function ($mock) {
            $mock->shouldReceive('walletBalances')->once()->andReturn([
                'base' => ['available' => 0.5, 'balance' => 0.5],
                'quote' => ['available' => 0.0, 'balance' => 0.0],
            ]);
        });

        $this->invokeAutoAnchor($config, 2000.0);

        $state = $config->fresh('state')->state;
        // Bullish-resolved direction watches for SELL (HOLDING/buy_anchor) --
        // before this fix, this bot would have been left IDLE forever.
        $this->assertSame(RatioBotState::STATE_HOLDING, $state->position_state);
        $this->assertSame('buy_anchor', $state->active_anchor_type);
    }

    public function test_no_backtest_record_at_all_means_the_trend_always_wins_on_disagreement(): void
    {
        $config = $this->makeBot();

        $result = $this->invokeResolve($config, 'bearish', ['composite_score' => -0.5], 'bullish', 60);

        $this->assertSame('bearish', $result['direction']);
        $this->assertNull($result['reason']);
        $this->assertStringContainsString('trend favored', (string) $result['resolution']);
    }

    public function test_a_sufficiently_reliable_forecast_can_outweigh_a_weak_trend(): void
    {
        $config = $this->makeBot();
        $this->seedBacktest($config, 85.0, 60);

        // Weak-but-non-neutral trend composite (0.2) against a high-hit-rate
        // (0.30 weight) forecast: trendContribution = 0.2*0.70=0.14,
        // forecastContribution = 1.0*0.30=0.30 -> forecast wins.
        $result = $this->invokeResolve($config, 'bearish', ['composite_score' => -0.2], 'bullish', 60);

        $this->assertSame('bullish', $result['direction']);
        $this->assertNull($result['reason']);
        $this->assertStringContainsString('forecast favored', (string) $result['resolution']);
    }

    public function test_agreement_case_skips_the_backtest_lookup_entirely(): void
    {
        $config = $this->makeBot();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $result = $this->invokeResolve($config, 'bullish', ['composite_score' => 0.8], 'bullish', 60);

        $this->assertSame('bullish', $result['direction']);
        $this->assertNull($result['reason']);
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('ratio_bot_forecast_backtests', $sql);
        }
    }

    public function test_legacy_single_window_trend_mode_keeps_the_original_unconditional_veto(): void
    {
        $config = $this->makeBot([
            'execution_mode' => 'live',
            'brain_enabled' => true,
            'strategy_params' => [
                'auto_start_direction_mode' => 'auto',
                'auto_start_min_confidence' => 60,
                'auto_start_observation_candles' => 12,
                'trend_scoring_mode' => 'legacy_single_window',
            ],
        ]);
        $this->seedTrend($config, 2100.0, -5.0, 12);
        $this->seedBrain($config, 'bullish', 80);
        // Even a maximally-reliable backtest reading must not matter here --
        // legacy mode has no composite score to weigh with, so weighting
        // must never even be attempted.
        $this->seedBacktest($config, 99.0);

        $direction = $this->invokeResolveAutoStartDirection($config, 2000.0, true, true);

        $this->assertNull($direction);
    }

    public function test_brain_disabled_bots_never_reach_the_weighting_branch(): void
    {
        // brain_enabled=false -> RatioBotBrainService::resolve() returns null
        // -> forecastDirection falls back to 'neutral' -> hard-fails the
        // earlier "forecast is bullish/bearish" check before the mismatch
        // branch is ever reached. Zero behavior change for this population.
        $config = $this->makeBot([
            'execution_mode' => 'live',
            'brain_enabled' => false,
            'strategy_params' => [
                'auto_start_direction_mode' => 'auto',
                'auto_start_min_confidence' => 60,
                'auto_start_observation_candles' => 12,
            ],
        ]);
        $this->seedTrend($config, 1900.0, 8.0, 48);
        $this->seedBacktest($config, 99.0);

        $direction = $this->invokeResolveAutoStartDirection($config, 2000.0, true, true);

        $this->assertNull($direction);
    }

    private function invokeResolve(RatioBotConfig $config, string $trendDirection, ?array $trendScore, string $forecastDirection, int $horizonSeconds): array
    {
        $job = new RunRatioBotEvaluationJob((string) $config->id);
        $method = new ReflectionMethod(RunRatioBotEvaluationJob::class, 'resolveDirectionWithReliabilityWeighting');
        $method->setAccessible(true);

        return $method->invoke($job, $config, $trendDirection, $trendScore, $forecastDirection, $horizonSeconds);
    }

    private function invokeResolveAutoStartDirection(RatioBotConfig $config, float $currentPrice, bool $hasBase, bool $hasQuote): ?string
    {
        $job = new RunRatioBotEvaluationJob((string) $config->id);
        $method = new ReflectionMethod(RunRatioBotEvaluationJob::class, 'resolveAutoStartDirection');
        $method->setAccessible(true);

        return $method->invoke($job, $config, $currentPrice, $hasBase, $hasQuote);
    }

    private function invokeAutoAnchor(RatioBotConfig $config, float $currentPrice): void
    {
        $job = new RunRatioBotEvaluationJob((string) $config->id);
        $method = new ReflectionMethod(RunRatioBotEvaluationJob::class, 'maybeAutoAnchor');
        $method->setAccessible(true);
        $method->invoke(
            $job,
            $config,
            $currentPrice,
            app(RatioBotRuntimeQueryService::class),
            app(\App\Domain\RatioBot\RatioBotStateService::class),
        );
    }

    private function seedBacktest(RatioBotConfig $config, float $hitRatePct, int $horizonSeconds = 60): RatioBotForecastBacktest
    {
        return RatioBotForecastBacktest::create([
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
            MarketOhlc::create([
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
            'workspace_id' => Workspace::factory()->create()->id,
            'symbol' => 'BTCUSDT',
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
}
