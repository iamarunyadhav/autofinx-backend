<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotRuntimeQueryService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\MarketOhlc;
use App\Models\RatioBotBrainSnapshot;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 1 close-out §1: WATCH_ONLY is provably equivalent to "no
 * starting_entry set" -- RatioBotConfig::startingEntry() already narrows to
 * BUY/SELL only (verified before implementing), so WATCH_ONLY needs zero
 * changes anywhere in maybeAutoAnchor()/the composite-confidence gate. These
 * tests confirm that equivalence directly by running the identical scenario
 * with starting_entry=null vs starting_entry=WATCH_ONLY and asserting
 * identical outcomes.
 */
class RatioBotWatchOnlyModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_with_watch_only_results_in_zero_lots_and_idle_state(): void
    {
        $config = $this->makeBot(['starting_entry' => 'WATCH_ONLY']);

        $this->assertSame(RatioBotState::STATE_IDLE, $config->state->position_state);
        $this->assertDatabaseCount('ratio_bot_lots', 0);
        $this->assertTrue($config->isWatchOnlyMode());
    }

    public function test_watch_only_and_unset_starting_entry_take_the_identical_composite_confidence_code_path(): void
    {
        // Same funded-quote-only, bearish-forecast-and-graph scenario as the
        // existing auto-direction test (RatioBotAutoAnchorTest) -- if
        // WATCH_ONLY is truly equivalent, both configs must anchor to the
        // identical BUY-watch outcome.
        $unset = $this->makeBot(['starting_entry' => null]);
        $watchOnly = $this->makeBot(['starting_entry' => 'WATCH_ONLY', 'symbol' => 'ETHUSDT']);

        foreach ([$unset, $watchOnly] as $config) {
            $this->seedTrend($config, 2100.0, -5.0, 12);
            $this->seedBrain($config, 'bearish', 72);
        }

        $this->mock(RatioBotRuntimeQueryService::class, function ($mock) {
            $mock->shouldReceive('walletBalances')->twice()->andReturn([
                'base' => ['available' => 0.0, 'balance' => 0.0],
                'quote' => ['available' => 1000.0, 'balance' => 1000.0],
            ]);
        });

        $this->invokeAutoAnchor($unset, 2000.0);
        $this->invokeAutoAnchor($watchOnly, 2000.0);

        $unsetState = $unset->fresh('state')->state;
        $watchOnlyState = $watchOnly->fresh('state')->state;

        $this->assertSame($unsetState->position_state, $watchOnlyState->position_state);
        $this->assertSame($unsetState->active_anchor_type, $watchOnlyState->active_anchor_type);
        $this->assertSame(RatioBotState::STATE_FLAT, $watchOnlyState->position_state);
        $this->assertSame('sell_anchor', $watchOnlyState->active_anchor_type);
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
            app(RatioBotStateService::class),
        );
    }

    private function makeBot(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'symbol' => 'BTCUSDT',
            'market_type' => 'spot',
            'timeframe' => '1m',
            'execution_mode' => 'live',
            'enabled' => true,
            'brain_enabled' => true,
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'strategy_params' => [
                'auto_start_direction_mode' => 'auto',
                'auto_start_min_confidence' => 60,
                'auto_start_observation_candles' => 12,
            ],
        ], $overrides));

        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_IDLE,
            'feed_status' => 'healthy',
        ]);
        $config->setRelation('state', $state);

        return $config;
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
}
