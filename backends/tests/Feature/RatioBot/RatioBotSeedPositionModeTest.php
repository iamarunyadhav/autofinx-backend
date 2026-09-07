<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\ScoredSellCandidateSelector;
use App\Jobs\RunRatioBotEvaluationJob;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 1 close-out §2: SEED_POSITION executes exactly one real BUY sized to
 * seed_position_pct% of base_quote_amount, reusing the existing
 * metadata.stage_quote_amount override RatioBotExecutionService::
 * resolveQuantity() already reads -- not a special-cased order call. The
 * resulting lot is an ordinary TYPE_NORMAL lot, confirmed here by reusing
 * ScoredSellCandidateSelectorTest's own assertion pattern against it.
 */
class RatioBotSeedPositionModeTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
    }

    public function test_the_first_tick_executes_exactly_one_seed_buy_sized_to_the_configured_pct(): void
    {
        $config = $this->makeBot(['mode' => 'restricted_auto', 'seed_position_pct' => 10, 'base_quote_amount' => 1000]);

        $this->invokeSeedPosition($config, currentPrice: 100.0);

        $decisions = RatioBotDecision::where('ratio_bot_config_id', $config->id)->get();
        $this->assertCount(1, $decisions);
        $decision = $decisions->first();
        $this->assertSame(RatioBotDecision::DECISION_BUY, $decision->decision);
        $this->assertEqualsWithDelta(100.0, (float) data_get($decision->metadata, 'stage_quote_amount'), 0.0001);

        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_SEED_POSITION_EXECUTED,
        ]);

        $lot = RatioBotLot::where('ratio_bot_config_id', $config->id)->first();
        $this->assertNotNull($lot);
        $this->assertSame(RatioBotLot::TYPE_NORMAL, $lot->lot_type);
        $this->assertEqualsWithDelta(1.0, (float) $lot->remaining_qty, 0.0001); // 100 quote / 100 price
    }

    public function test_the_seed_buy_never_repeats_on_a_later_tick_even_if_still_idle(): void
    {
        $config = $this->makeBot(['mode' => 'restricted_auto', 'seed_position_pct' => 10, 'base_quote_amount' => 1000]);

        $this->invokeSeedPosition($config, currentPrice: 100.0);
        // Force the bot back to idle (as if the fill somehow didn't move it)
        // and re-invoke -- the executed_at marker must prevent a second buy.
        $config->fresh('state')->state->update(['position_state' => RatioBotState::STATE_IDLE]);
        $this->invokeSeedPosition($config->fresh('state'), currentPrice: 100.0);

        $this->assertSame(1, RatioBotDecision::where('ratio_bot_config_id', $config->id)->count());
    }

    public function test_manual_approval_mode_surfaces_the_seed_buy_as_a_pending_suggestion_instead_of_silently_doing_nothing(): void
    {
        $config = $this->makeBot(['mode' => 'manual_approval', 'seed_position_pct' => 10, 'base_quote_amount' => 1000]);

        $this->invokeSeedPosition($config, currentPrice: 100.0);

        $decision = RatioBotDecision::where('ratio_bot_config_id', $config->id)->first();
        $this->assertSame(RatioBotDecision::EXEC_SKIPPED, $decision->execution_status);
        $this->assertTrue((bool) data_get($decision->metadata, 'requires_manual_approval'));
        $this->assertSame(0, RatioBotLot::query()->count(), 'a manual-approval seed buy must not fill until approved');
    }

    public function test_the_seeded_lot_is_ranked_by_the_existing_sell_selector_exactly_like_any_other_normal_lot(): void
    {
        $config = $this->makeBot(['mode' => 'restricted_auto', 'seed_position_pct' => 10, 'base_quote_amount' => 1000]);
        $this->invokeSeedPosition($config, currentPrice: 100.0);

        $lot = RatioBotLot::where('ratio_bot_config_id', $config->id)->firstOrFail();
        $ranked = app(ScoredSellCandidateSelector::class)->rank($config->fresh('state'), [(string) $lot->id], currentPrice: 110.0);

        $this->assertCount(1, $ranked);
        $this->assertSame((string) $lot->id, $ranked[0]['lot_id']);
        $this->assertGreaterThan(0, $ranked[0]['net_profit_pct']);
    }

    public function test_seed_position_pct_above_fifty_is_rejected_by_the_config_clamp(): void
    {
        $config = $this->makeBot(['seed_position_pct' => 90]);

        $this->assertEqualsWithDelta(50.0, $config->seedPositionPct(), 0.0001);
    }

    public function test_seed_position_pct_above_fifty_is_rejected_at_the_api_validation_layer(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        Queue::fake();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ratio-bots", [
                'symbol' => 'BTCUSDT',
                'sell_threshold_ratio' => 0.50,
                'buy_threshold_ratio' => 0.25,
                'base_quote_amount' => 100.0,
                'starting_entry' => 'SEED_POSITION',
                'seed_position_pct' => 90,
            ]);

        $response->assertStatus(422);
    }

    public function test_seed_position_pct_is_required_when_starting_entry_is_seed_position(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        Queue::fake();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ratio-bots", [
                'symbol' => 'BTCUSDT',
                'sell_threshold_ratio' => 0.50,
                'buy_threshold_ratio' => 0.25,
                'base_quote_amount' => 100.0,
                'starting_entry' => 'SEED_POSITION',
            ]);

        $response->assertStatus(422);
    }

    private function invokeSeedPosition(RatioBotConfig $config, float $currentPrice): void
    {
        $job = new RunRatioBotEvaluationJob((string) $config->id);
        $method = new ReflectionMethod(RunRatioBotEvaluationJob::class, 'maybeExecuteSeedPosition');
        $method->setAccessible(true);
        $method->invoke($job, $config, $currentPrice);
    }

    private function makeBot(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'timeframe' => '1m',
            'algorithm' => 'ratio_threshold',
            'execution_mode' => 'sandbox',
            'starting_entry' => 'SEED_POSITION',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'enabled' => true,
            // Lot-based accounting (RatioBotLot rows) is gated behind
            // RatioBotStagePlanService::enabled(), which requires v2_enabled
            // AND a non-empty stage_buy_allocations -- confirmed via
            // RatioBotStateService::onBuyFilled(). Without both, a fill only
            // updates RatioBotState's anchor fields, no RatioBotLot row at
            // all (the pre-V2 behavior) -- not meaningful for testing "the
            // resulting lot is TYPE_NORMAL", so every test in this file uses
            // the V2/lot-based path deliberately.
            'v2_enabled' => true,
            'stage_buy_allocations' => [100],
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
