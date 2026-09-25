<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotDecisionService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Domain\RatioBot\RatioBotTradeOutcomeService;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\RatioBotTradeOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Ratio Bot trade-level accuracy tracking (observation only). Covers capture
 * at the real fill choke point (RatioBotStateService), the BUY/SELL scoring
 * definitions, the honest pending/insufficient_data states, idempotency,
 * and the fail-safe guarantee that a broken outcome table can never break a
 * fill.
 */
class RatioBotTradeOutcomeServiceTest extends TestCase
{
    use RefreshDatabase;

    private RatioBotStateService $states;
    private RatioBotTradeOutcomeService $outcomes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->states = new RatioBotStateService();
        $this->outcomes = new RatioBotTradeOutcomeService();
        // Small, deterministic windows: 6 x 1m candles for BUY, 3 for SELL.
        config([
            'ratio_bot_outcomes.buy_horizon_hours' => 0.1,
            'ratio_bot_outcomes.sell_lookahead_hours' => 0.05,
            'ratio_bot_outcomes.close_grace_seconds' => 120,
            'ratio_bot_outcomes.min_candle_coverage' => 0.8,
            'ratio_bot_outcomes.max_evaluation_attempts' => 3,
        ]);
        $this->travelTo(Carbon::createFromTimestamp(1788256830)); // hh:mm:30, mid-candle
    }

    /** @test */
    public function cost_ratio_mirror_matches_the_engines_own_private_execution_cost_ratio(): void
    {
        $engineMethod = new ReflectionMethod(RatioBotDecisionService::class, 'executionCostRatio');
        $engineMethod->setAccessible(true);
        $engine = new RatioBotDecisionService();

        foreach ([[0, 0], [10, 0], [10, 5], [7.5, 12.25], [-3, 4]] as [$fee, $slip]) {
            $config = new RatioBotConfig(['fee_bps' => $fee, 'max_slippage_bps' => $slip]);
            $this->assertSame(
                $engineMethod->invoke($engine, $config),
                $this->outcomes->executionCostRatio($config),
                "cost ratio drifted from the engine for fee={$fee} slippage={$slip}",
            );
        }
    }

    /** @test */
    public function a_real_stage_buy_fill_is_captured_with_the_engines_net_profit_floor(): void
    {
        [$config] = $this->makeStageConfig(['fee_bps' => 10, 'max_slippage_bps' => 5, 'min_net_profit_bps' => 20]);
        $decision = $this->makeDecision($config, RatioBotDecision::DECISION_BUY, ['trigger' => 'stage_buy', 'stage' => ['side' => 'BUY', 'index' => 1, 'allocation_pct' => 50]]);

        $this->states->onBuyFilled($config->fresh('state'), $decision, 60000, 0.001, '123456', 'c1');

        $row = RatioBotTradeOutcome::sole();
        $lot = RatioBotLot::sole();
        $this->assertSame((string) $decision->id, (string) $row->ratio_bot_decision_id);
        $this->assertSame((string) $lot->id, (string) $row->ratio_bot_lot_id);
        $this->assertSame('BUY', $row->side);
        $this->assertSame(1, $row->stage_index);
        $this->assertSame('stage_buy', $row->trigger);
        $this->assertSame(RatioBotTradeOutcome::STATUS_PENDING, $row->evaluation_status);
        $this->assertNull($row->verdict);
        $this->assertNull($row->decision_correct);
        // (2 x 10 + 5) / 10000 = 0.0025 cost, + 20 bps min net = 0.0045.
        $this->assertEqualsWithDelta(0.0025, (float) $row->cost_ratio, 1e-12);
        $this->assertEqualsWithDelta(0.0045, (float) $row->required_exit_ratio, 1e-12);
        $this->assertSame('BTCUSDT', $row->symbol);
        $this->assertSame('1m', $row->timeframe);
        $this->assertSame(6, $row->candles_expected);
        // The fill itself is untouched by capture.
        $this->assertSame(RatioBotDecision::EXEC_FILLED, $decision->fresh()->execution_status);
    }

    /** @test */
    public function a_recovery_fill_merged_into_the_average_lot_uses_the_recovery_lot_floor(): void
    {
        [$config] = $this->makeStageConfig(['fee_bps' => 10, 'max_slippage_bps' => 0, 'min_net_profit_bps' => 100, 'recovery_lot_min_profit_ratio' => 0.004]);
        $existing = RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'cycle_number' => 1,
            'buy_stage_index' => 4,
            'lot_type' => RatioBotLot::TYPE_RECOVERY,
            'is_recovery_average' => true,
            'buy_price' => 50000,
            'original_qty' => 0.001,
            'remaining_qty' => 0.001,
            'spent_quote' => 50,
            'status' => RatioBotLot::STATUS_OPEN,
            'bought_at' => now(),
        ]);
        $decision = $this->makeDecision($config, RatioBotDecision::DECISION_BUY, ['stage' => ['side' => 'BUY', 'index' => 4, 'type' => RatioBotLot::TYPE_RECOVERY]]);

        $this->states->onBuyFilled($config->fresh('state'), $decision, 48000, 0.001, '999', 'c2');

        $row = RatioBotTradeOutcome::sole();
        $this->assertTrue($row->is_recovery_lot);
        $this->assertSame((string) $existing->id, (string) $row->ratio_bot_lot_id);
        $this->assertEqualsWithDelta($config->fresh()->recoveryLotMinProfitRatio(), (float) $row->required_exit_ratio, 1e-12);
    }

    /** @test */
    public function capture_is_idempotent_per_decision(): void
    {
        [$config] = $this->makeStageConfig();
        $decision = $this->makeDecision($config, RatioBotDecision::DECISION_BUY, ['stage' => ['index' => 1]]);

        $this->outcomes->captureBuyFill($config, $decision, 60000, 0.001, '1');
        $this->outcomes->captureBuyFill($config, $decision, 60000, 0.001, '1');

        $this->assertSame(1, RatioBotTradeOutcome::count());
    }

    /** @test */
    public function synthetic_anchors_and_futures_bots_are_never_scored(): void
    {
        [$config] = $this->makeStageConfig();
        $this->states->anchorManualBuy($config->fresh('state'), 60000, 0.001, 'auto-anchor');
        $this->assertSame(0, RatioBotTradeOutcome::count());

        [$futures] = $this->makeStageConfig(['market_type' => 'futures']);
        $decision = $this->makeDecision($futures, RatioBotDecision::DECISION_BUY);
        $this->assertNull($this->outcomes->captureBuyFill($futures, $decision, 60000, 0.001, '1'));
        $this->assertSame(0, RatioBotTradeOutcome::count());
    }

    /** @test */
    public function a_broken_outcome_table_never_breaks_a_real_fill(): void
    {
        [$config] = $this->makeStageConfig();
        Schema::drop('ratio_bot_trade_outcomes');
        $decision = $this->makeDecision($config, RatioBotDecision::DECISION_BUY, ['stage' => ['index' => 1]]);

        $state = $this->states->onBuyFilled($config->fresh('state'), $decision, 60000, 0.001, '42', 'c3');

        $this->assertSame(RatioBotState::STATE_HOLDING, $state->position_state);
        $this->assertSame(RatioBotDecision::EXEC_FILLED, $decision->fresh()->execution_status);
        $this->assertSame(1, RatioBotLot::count());
    }

    /** @test */
    public function capture_can_be_switched_off(): void
    {
        config(['ratio_bot_outcomes.capture_enabled' => false]);
        [$config] = $this->makeStageConfig();
        $decision = $this->makeDecision($config, RatioBotDecision::DECISION_BUY, ['stage' => ['index' => 1]]);

        $this->states->onBuyFilled($config->fresh('state'), $decision, 60000, 0.001, '1', 'c');

        $this->assertSame(0, RatioBotTradeOutcome::count());
    }

    /** @test */
    public function buy_stays_pending_until_its_bounded_window_closes(): void
    {
        [$config, $row] = $this->capturedBuy(entry: 60000, costBps: 10, minNetBps: 0); // floor 60120
        $this->seedCandles($config, $row, highs: [60000, 60500, 60000, 60000, 60000, 60000]);

        $this->travel(3)->minutes();
        $this->outcomes->evaluate($row->fresh());

        $fresh = $row->fresh();
        $this->assertSame(RatioBotTradeOutcome::STATUS_PENDING, $fresh->evaluation_status);
        $this->assertNull($fresh->verdict);
        $this->assertNull($fresh->decision_correct);
    }

    /** @test */
    public function buy_that_reached_its_net_floor_is_scored_recovered(): void
    {
        [$config, $row] = $this->capturedBuy(entry: 60000, costBps: 10, minNetBps: 0); // cost 0.002 -> floor 60120
        $this->seedCandles($config, $row, highs: [59900, 60200, 60000, 59800, 59900, 60000], lows: [59000, 59500, 59400, 59300, 59600, 59700]);

        $this->travel(10)->minutes();
        $this->outcomes->evaluate($row->fresh());

        $fresh = $row->fresh();
        $this->assertSame(RatioBotTradeOutcome::STATUS_EVALUATED, $fresh->evaluation_status);
        $this->assertSame(RatioBotTradeOutcome::VERDICT_BUY_RECOVERED, $fresh->verdict);
        $this->assertTrue($fresh->decision_correct);
        $this->assertEqualsWithDelta(60200, (float) $fresh->best_price, 1e-6);
        $this->assertEqualsWithDelta(59000, (float) $fresh->worst_price, 1e-6);
        $this->assertEqualsWithDelta(60200 / 60000 - 1 - 0.002, (float) $fresh->best_net_edge_ratio, 1e-9);
        $this->assertEqualsWithDelta(59000 / 60000 - 1, (float) $fresh->max_adverse_ratio, 1e-9);
        $this->assertNotNull($fresh->reached_floor_at);
        $this->assertSame(6, $fresh->candles_observed);
    }

    /** @test */
    public function buy_whose_gross_rise_never_covered_costs_is_scored_not_recovered(): void
    {
        // Price rose 0.15% -- gross profitable, but below the 0.2% round-trip cost floor.
        [$config, $row] = $this->capturedBuy(entry: 60000, costBps: 10, minNetBps: 0);
        $this->seedCandles($config, $row, highs: [60090, 60050, 60000, 60000, 60000, 60000]);

        $this->travel(10)->minutes();
        $this->outcomes->evaluate($row->fresh());

        $fresh = $row->fresh();
        $this->assertSame(RatioBotTradeOutcome::VERDICT_BUY_NOT_RECOVERED, $fresh->verdict);
        $this->assertFalse($fresh->decision_correct);
        $this->assertNull($fresh->reached_floor_at);
    }

    /** @test */
    public function buy_with_missing_candles_retries_then_settles_on_insufficient_data_never_a_guess(): void
    {
        [$config, $row] = $this->capturedBuy(entry: 60000, costBps: 10, minNetBps: 0);
        $this->seedCandles($config, $row, highs: [60000, 60000]); // 2 of 6 -> 33% coverage

        $this->travel(10)->minutes();
        $this->outcomes->evaluate($row->fresh());
        $this->assertSame(RatioBotTradeOutcome::STATUS_PENDING, $row->fresh()->evaluation_status);
        $this->assertSame('awaiting_candle_coverage', $row->fresh()->status_reason);

        foreach ([1, 2] as $_) {
            $this->travel(6)->minutes();
            $this->outcomes->evaluate($row->fresh());
        }

        $fresh = $row->fresh();
        $this->assertSame(RatioBotTradeOutcome::STATUS_INSUFFICIENT_DATA, $fresh->evaluation_status);
        $this->assertSame('insufficient_candle_coverage', $fresh->status_reason);
        $this->assertNull($fresh->verdict);
        $this->assertNull($fresh->decision_correct);
    }

    /** @test */
    public function a_real_sell_fill_of_the_lot_counts_as_evidence_even_with_candle_gaps(): void
    {
        [$config, $row] = $this->capturedBuy(entry: 60000, costBps: 10, minNetBps: 0);
        $lot = RatioBotLot::findOrFail($row->ratio_bot_lot_id);
        $lot->update(['sell_fills' => [[
            'decision_id' => 'x', 'order_id' => 'o', 'price' => 60300, 'qty' => 0.001, 'realized_pnl' => 0.3,
            'sold_at' => now()->addMinutes(2)->toIso8601String(),
        ]]]);

        $this->travel(10)->minutes();
        $this->outcomes->evaluate($row->fresh());

        $fresh = $row->fresh();
        $this->assertSame(RatioBotTradeOutcome::VERDICT_BUY_RECOVERED, $fresh->verdict);
        $this->assertSame('positive_evidence_with_partial_coverage', $fresh->status_reason);
    }

    /** @test */
    public function sell_fill_records_realized_net_from_the_lots_it_consumed_and_scores_timing(): void
    {
        [$config, $state] = $this->makeStageConfig(['fee_bps' => 10, 'max_slippage_bps' => 0]);
        $lot = RatioBotLot::create([
            'ratio_bot_config_id' => $config->id, 'cycle_number' => 1, 'buy_stage_index' => 1,
            'buy_price' => 60000, 'original_qty' => 0.001, 'remaining_qty' => 0.001, 'spent_quote' => 60,
            'status' => RatioBotLot::STATUS_OPEN, 'bought_at' => now(),
        ]);
        $state->update(['position_state' => RatioBotState::STATE_HOLDING, 'last_buy_price' => 60000, 'last_buy_quantity' => 0.001]);
        $decision = $this->makeDecision($config, RatioBotDecision::DECISION_SELL, [
            'trigger' => 'stage_fifo_lot_sell', 'sell_lot_ids' => [(string) $lot->id], 'stage' => ['side' => 'SELL', 'index' => 1],
        ]);

        $this->states->onSellFilled($config->fresh('state'), $decision, 63000, 0.001, '777', 'c9');

        $row = RatioBotTradeOutcome::sole();
        $this->assertSame('SELL', $row->side);
        $this->assertEqualsWithDelta(60000, (float) $row->cost_basis_price, 1e-6);
        $this->assertEqualsWithDelta(63000 / 60000 - 1 - 0.002, (float) $row->realized_net_ratio, 1e-9);
        $this->assertTrue($row->net_profitable);
        $this->assertSame((string) $lot->id, (string) $row->ratio_bot_lot_id);
        $this->assertSame(RatioBotTradeOutcome::STATUS_PENDING, $row->evaluation_status);

        // Lookahead: best high 63100 = +0.159% <= 0.2% cost -> well timed.
        $this->seedCandles($config, $row, highs: [63050, 63100, 62900], lows: [62500, 62000, 62100]);
        $this->travel(10)->minutes();
        $this->outcomes->evaluate($row->fresh());

        $fresh = $row->fresh();
        $this->assertSame(RatioBotTradeOutcome::VERDICT_SELL_WELL_TIMED, $fresh->verdict);
        $this->assertTrue($fresh->decision_correct);
        $this->assertEqualsWithDelta(63100 / 63000 - 1, (float) $fresh->missed_edge_ratio, 1e-9);
        $this->assertEqualsWithDelta(1 - 62000 / 63000, (float) $fresh->avoided_drawdown_ratio, 1e-9);
    }

    /** @test */
    public function sell_followed_by_a_rise_larger_than_one_round_trip_cost_is_sold_early(): void
    {
        [$config, $state] = $this->makeStageConfig(['fee_bps' => 10, 'max_slippage_bps' => 0]);
        $state->update(['position_state' => RatioBotState::STATE_HOLDING, 'last_buy_price' => 60000, 'last_buy_quantity' => 0.001]);
        $decision = $this->makeDecision($config, RatioBotDecision::DECISION_SELL, ['trigger' => 'legacy_sell']);

        // Legacy (no lot ids) path: cost basis falls back to the state's weighted entry.
        $this->states->onSellFilled($config->fresh('state'), $decision, 61000, 0.001, '888', 'c10');
        $row = RatioBotTradeOutcome::sole();
        $this->assertSame('state_weighted_entry', $row->metadata['cost_basis_source']);

        $this->seedCandles($config, $row, highs: [61200, 61500, 61400]);
        $this->travel(10)->minutes();
        $this->outcomes->evaluate($row->fresh());

        $fresh = $row->fresh();
        $this->assertSame(RatioBotTradeOutcome::VERDICT_SELL_EARLY, $fresh->verdict);
        $this->assertFalse($fresh->decision_correct);
    }

    /** @test */
    public function loop_sweep_is_throttled_per_bot_and_can_be_disabled(): void
    {
        [$config, $row] = $this->capturedBuy(entry: 60000, costBps: 10, minNetBps: 0);
        $this->seedCandles($config, $row, highs: [60500, 60000, 60000, 60000, 60000, 60000]);
        $this->travel(10)->minutes();

        config(['ratio_bot_outcomes.evaluate_in_loop' => false]);
        $this->assertSame(0, $this->outcomes->evaluateDueForConfig($config));
        $this->assertSame(RatioBotTradeOutcome::STATUS_PENDING, $row->fresh()->evaluation_status);

        config(['ratio_bot_outcomes.evaluate_in_loop' => true]);
        $this->assertSame(1, $this->outcomes->evaluateDueForConfig($config));
        $this->assertSame(RatioBotTradeOutcome::STATUS_EVALUATED, $row->fresh()->evaluation_status);

        // Throttled: a second sweep inside the throttle window does no work.
        $this->assertSame(0, $this->outcomes->evaluateDueForConfig($config));
    }

    /** @test */
    public function the_existing_evaluation_job_tick_drives_the_lazy_evaluation(): void
    {
        [$config, $row] = $this->capturedBuy(entry: 60000, costBps: 10, minNetBps: 0);
        $this->seedCandles($config, $row, highs: [60500, 60000, 60000, 60000, 60000, 60000]);
        $this->travel(10)->minutes();

        $this->runEvaluationJobTick($config);

        $fresh = $row->fresh();
        $this->assertSame(RatioBotTradeOutcome::STATUS_EVALUATED, $fresh->evaluation_status);
        $this->assertSame(RatioBotTradeOutcome::VERDICT_BUY_RECOVERED, $fresh->verdict);
    }

    /** @test */
    public function the_job_tick_leaves_rows_pending_when_the_loop_flag_is_off(): void
    {
        config(['ratio_bot_outcomes.evaluate_in_loop' => false]);
        [$config, $row] = $this->capturedBuy(entry: 60000, costBps: 10, minNetBps: 0);
        $this->seedCandles($config, $row, highs: [60500, 60000, 60000, 60000, 60000, 60000]);
        $this->travel(10)->minutes();

        $this->runEvaluationJobTick($config);

        $this->assertSame(RatioBotTradeOutcome::STATUS_PENDING, $row->fresh()->evaluation_status);
    }

    /** @test */
    public function manual_command_evaluates_due_rows_for_stopped_bots(): void
    {
        [$config, $row] = $this->capturedBuy(entry: 60000, costBps: 10, minNetBps: 0);
        $config->update(['enabled' => false]);
        $this->seedCandles($config, $row, highs: [60500, 60000, 60000, 60000, 60000, 60000]);
        $this->travel(10)->minutes();

        $this->artisan('ratio-bot:evaluate-trade-outcomes')->assertSuccessful();

        $this->assertSame(RatioBotTradeOutcome::VERDICT_BUY_RECOVERED, $row->fresh()->verdict);
    }

    /** @test */
    public function summary_excludes_non_evaluated_rows_and_withholds_percentages_below_min_samples(): void
    {
        [$config] = $this->makeStageConfig();
        $mk = fn (string $side, string $status, ?bool $correct, array $extra = []) => RatioBotTradeOutcome::create(array_merge([
            'ratio_bot_config_id' => $config->id,
            'ratio_bot_decision_id' => (string) \Illuminate\Support\Str::uuid(),
            'side' => $side, 'exchange' => 'binance', 'symbol' => 'BTCUSDT', 'timeframe' => '1m',
            'executed_price' => 100, 'executed_qty' => 1, 'executed_at' => now(), 'horizon_seconds' => 60,
            'evaluation_status' => $status, 'decision_correct' => $correct,
        ], $extra));

        $mk('BUY', 'evaluated', true, ['best_net_edge_ratio' => 0.01]);
        $mk('BUY', 'evaluated', false, ['best_net_edge_ratio' => -0.002]);
        $mk('BUY', 'pending', null);
        $mk('BUY', 'insufficient_data', null);
        $mk('SELL', 'evaluated', true, ['missed_edge_ratio' => 0.001, 'net_profitable' => true, 'realized_net_ratio' => 0.02]);
        $mk('SELL', 'not_applicable', null);

        config(['ratio_bot_outcomes.min_samples_for_accuracy' => 30]);
        $summary = $this->outcomes->summary(RatioBotTradeOutcome::query());
        $this->assertSame(4, $summary['buy']['captured']);
        $this->assertSame(2, $summary['buy']['evaluated']);
        $this->assertSame(1, $summary['buy']['pending']);
        $this->assertSame(1, $summary['buy']['insufficient_data']);
        $this->assertNull($summary['buy']['good_pct']);
        $this->assertSame('insufficient_data', $summary['buy']['status']);
        $this->assertSame(1, $summary['sell']['not_applicable']);

        config(['ratio_bot_outcomes.min_samples_for_accuracy' => 1]);
        $summary = $this->outcomes->summary(RatioBotTradeOutcome::query());
        $this->assertSame(50.0, $summary['buy']['good_pct']);
        $this->assertSame(0.4, $summary['buy']['avg_best_net_edge_pct']);
        $this->assertSame(100.0, $summary['sell']['well_timed_pct']);
        $this->assertSame(100.0, $summary['sell']['net_profitable_pct']);
        $this->assertSame('observed_sample', $summary['buy']['status']);
    }

    // -----------------------------------------------------------------

    /**
     * @return array{0: RatioBotConfig, 1: RatioBotTradeOutcome}
     */
    private function capturedBuy(float $entry, float $costBps, float $minNetBps): array
    {
        [$config] = $this->makeStageConfig(['fee_bps' => $costBps, 'max_slippage_bps' => 0, 'min_net_profit_bps' => $minNetBps]);
        $decision = $this->makeDecision($config, RatioBotDecision::DECISION_BUY, ['stage' => ['side' => 'BUY', 'index' => 1]]);
        $this->states->onBuyFilled($config->fresh('state'), $decision, $entry, 0.001, '1001', 'cb');

        return [$config->fresh('state'), RatioBotTradeOutcome::sole()];
    }

    /**
     * Runs RunRatioBotEvaluationJob::handle() for one tick with the trading
     * engine and market-data collaborators stubbed (a HOLD tick) -- proves
     * the job itself reaches the outcome sweep after its trading work.
     */
    private function runEvaluationJobTick(RatioBotConfig $config): void
    {
        $quotes = \Mockery::mock(\App\Domain\MarketData\CanonicalQuoteService::class);
        $quotes->shouldReceive('forRatioBot')->andReturn([
            'price' => 60000.0, 'source' => 'test', 'timestamp' => now(), 'price_age_seconds' => 0, 'latency_ms' => 0,
        ]);
        $breakers = \Mockery::mock(\App\Domain\Ops\OperationalBreakerService::class);
        $breakers->shouldReceive('staleFeedState')->andReturn([]);
        $breakers->shouldReceive('providerState')->andReturn([]);
        $health = \Mockery::mock(\App\Domain\Exchange\BinanceSpotHealthService::class)->shouldIgnoreMissing();
        $health->shouldReceive('snapshot')->andReturn([]);
        $engine = \Mockery::mock(\App\Domain\RatioBot\RatioBotRealtimeEngine::class);
        $engine->shouldReceive('evaluate')->once()->andReturn(['decision' => RatioBotDecision::DECISION_HOLD]);
        $alerts = \Mockery::mock(\App\Domain\RatioBot\RatioBotAlertService::class)->shouldIgnoreMissing();

        (new \App\Jobs\RunRatioBotEvaluationJob((string) $config->id))->handle(
            new RatioBotDecisionService(),
            $engine,
            $this->states,
            \Mockery::mock(\App\Domain\Market\MarketPriceCacheService::class)->shouldIgnoreMissing(),
            $quotes,
            \Mockery::mock(\App\Domain\MarketData\MarketRecoveryManager::class)->shouldIgnoreMissing(),
            \Mockery::mock(\App\Domain\Exchange\ExchangeClientFactory::class)->shouldIgnoreMissing(),
            $breakers,
            $health,
            $alerts,
            \Mockery::mock(\App\Domain\RatioBot\RatioBotRuntimeQueryService::class)->shouldIgnoreMissing(),
        );
    }

    private function seedCandles(RatioBotConfig $config, RatioBotTradeOutcome $row, array $highs, array $lows = []): void
    {
        $window = $this->outcomes->window($row->executed_at->getTimestamp(), (int) $row->horizon_seconds, 60);
        foreach (array_values($highs) as $i => $high) {
            $low = $lows[$i] ?? $high - 100;
            MarketOhlc::create([
                'exchange' => $config->exchangeKey(),
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => '1m',
                'ts' => Carbon::createFromTimestamp($window['first_open'] + $i * 60),
                'open' => $low, 'high' => $high, 'low' => $low, 'close' => $low, 'volume' => 1,
            ]);
        }
    }

    private function makeDecision(RatioBotConfig $config, string $side, array $metadata = []): RatioBotDecision
    {
        return RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => $side,
            'evaluated_price' => 60000,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => $metadata ?: null,
            'decided_at' => now(),
        ]);
    }

    /**
     * @return array{0: RatioBotConfig, 1: RatioBotState}
     */
    private function makeStageConfig(array $overrides = []): array
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => '00000000-0000-0000-0000-000000000001',
            'name' => 'outcome-test-' . \Illuminate\Support\Str::random(8),
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'timeframe' => '1m',
            'algorithm' => 'ratio_threshold',
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'v2_enabled' => true,
            'base_quote_amount' => 100,
            'stage_buy_allocations' => [50, 25, 25],
            'stage_buy_drops' => [10, 5, 5],
            'stage_sell_allocations' => [25, 25, 50],
            'stage_sell_rises' => [5, 5, 10],
            'min_net_profit_bps' => 0,
            'fee_bps' => 0,
            'max_slippage_bps' => 0,
        ], $overrides));
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_FLAT,
            'last_sell_price' => 70000,
            'active_anchor_price' => 70000,
            'active_anchor_type' => 'sell_anchor',
        ]);
        $config->setRelation('state', $state);

        return [$config, $state];
    }
}
