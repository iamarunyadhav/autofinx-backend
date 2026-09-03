<?php

namespace Tests\Feature\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RatioBotApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'two_factor_confirmed_at' => now(),
        ]);
        $this->workspace = Workspace::factory()->create();

        // store()/update() dispatch BackfillMarketOhlcJob (real Binance REST
        // call). With QUEUE_CONNECTION=sync in tests that would otherwise
        // run synchronously inside every HTTP request here — fake it so CRUD
        // tests never make a live network call.
        Queue::fake();
    }

    // -----------------------------------------------------------------------
    // Config CRUD
    // -----------------------------------------------------------------------

    /** @test */
    public function can_create_ratio_bot_config(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ratio-bots", [
                'symbol'               => 'BTCUSDT',
                'sell_threshold_ratio' => 0.50,
                'buy_threshold_ratio'  => 0.25,
                'base_quote_amount'    => 100.0,
                'execution_mode'       => 'sandbox',
                'mode'                 => 'restricted_auto',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('symbol', 'BTCUSDT')
            ->assertJsonPath('sell_threshold_ratio', '0.500000')
            ->assertJsonPath('enabled', false);

        $this->assertDatabaseHas('ratio_bot_configs', ['symbol' => 'BTCUSDT']);
        $this->assertDatabaseHas('ratio_bot_states', []);
    }

    /** @test */
    public function can_list_ratio_bots_for_workspace(): void
    {
        RatioBotConfig::create([
            'workspace_id'        => $this->workspace->id,
            'symbol'              => 'ETHUSDT',
            'sell_threshold_ratio'=> 0.30,
            'buy_threshold_ratio' => 0.15,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/ratio-bots");

        $response->assertOk()->assertJsonCount(1);
    }

    /** @test */
    public function can_update_thresholds(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/ratio-bots/{$config->id}", [
                'sell_threshold_ratio' => 0.75,
                'buy_threshold_ratio'  => 0.30,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('ratio_bot_configs', [
            'id' => $config->id,
        ]);
    }

    /** @test */
    public function can_create_trailing_ratio_bot_with_negative_buy_threshold(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ratio-bots", [
                'symbol' => 'BTCUSDT',
                'trigger_mode' => 'trailing_ratio',
                'sell_threshold_ratio' => 0.50,
                'buy_threshold_ratio' => -0.50,
                'selling_loss_ratio' => 0.20,
                'buying_loss_ratio' => 0.20,
                'base_quote_amount' => 100.0,
                'execution_mode' => 'sandbox',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('trigger_mode', 'trailing_ratio')
            ->assertJsonPath('buy_threshold_ratio', '-0.500000');
    }

    /** @test */
    public function can_create_ratio_bot_with_live_hardening_guards(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ratio-bots", [
                'symbol' => 'BTCUSDT',
                'trigger_mode' => 'trailing_ratio',
                'sell_threshold_ratio' => 0.50,
                'buy_threshold_ratio' => -0.50,
                'selling_loss_ratio' => 0.20,
                'buying_loss_ratio' => 0.20,
                'max_allowed_slippage_bps' => 50,
                'max_trades_per_hour' => 4,
                'max_trades_per_day' => 12,
                'trigger_confirm_seconds' => 3,
                'trigger_confirm_updates' => 2,
                'use_candle_close_confirmation' => false,
                'min_price_stability_seconds' => 2,
                'max_spike_deviation_bps' => 500,
                'base_quote_amount' => 100.0,
                'execution_mode' => 'sandbox',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('max_allowed_slippage_bps', '50.0000')
            ->assertJsonPath('trigger_confirm_updates', 2);
    }

    /** @test */
    public function invalid_threshold_rejected(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/ratio-bots/{$config->id}", [
                'sell_threshold_ratio' => -0.1, // invalid
            ]);

        $response->assertStatus(422);
    }

    // -----------------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------------

    /** @test */
    public function can_start_bot(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/ratio-bots/{$config->id}/start");

        $response->assertOk();
        $this->assertDatabaseHas('ratio_bot_configs', ['id' => $config->id, 'enabled' => true]);
    }

    /** @test */
    public function can_pause_bot(): void
    {
        $config = $this->createConfig();
        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/start");

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/ratio-bots/{$config->id}/pause", ['reason' => 'test pause']);

        $response->assertOk();
        $this->assertDatabaseHas('ratio_bot_states', [
            'ratio_bot_config_id' => $config->id,
            'position_state'      => 'paused',
        ]);
    }

    /** @test */
    public function can_resume_bot_from_paused(): void
    {
        $config = $this->createConfig();
        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/start");
        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/pause");

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/ratio-bots/{$config->id}/resume");

        $response->assertOk();
        $state = RatioBotState::where('ratio_bot_config_id', $config->id)->first();
        $this->assertNotSame('paused', $state->position_state);
    }

    /** @test */
    public function can_resume_bot_from_blocked(): void
    {
        // Previously resume() only accepted STATE_PAUSED — a bot hard-blocked
        // by block() (order-failure streak, exchange-unhealthy, etc.) could
        // never be brought back via this endpoint.
        $config = $this->createConfig();
        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/start");
        // start() enabled the config via its own HTTP-request-scoped model
        // instance — refresh this test's local $config so it reflects that
        // before block() computes its dirty-attribute diff against it.
        $config = $config->fresh();
        app(\App\Domain\RatioBot\RatioBotStateService::class)->block($config, 'order_failure_streak', 'manual review required');

        $blockedState = RatioBotState::where('ratio_bot_config_id', $config->id)->first();
        $this->assertSame('blocked', $blockedState->position_state);
        $this->assertFalse($config->fresh()->enabled);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/ratio-bots/{$config->id}/resume");

        $response->assertOk();
        $state = RatioBotState::where('ratio_bot_config_id', $config->id)->first();
        $this->assertNotSame('blocked', $state->position_state);
        $this->assertNull($state->block_reason_code);
        $this->assertFalse((bool) $state->recovery_action_required);
        $this->assertTrue($config->fresh()->enabled);
    }

    /** @test */
    public function can_recalculate_and_resume_from_paused_without_rewriting_strategy_history(): void
    {
        $config = $this->createConfig([
            'v2_enabled' => true,
            'stage_buy_allocations' => [50, 25, 25],
            'stage_buy_drops' => [10, 5, 5],
            'stage_sell_allocations' => [25, 25, 50],
            'stage_sell_rises' => [5, 5, 10],
        ]);
        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/start");
        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/pause");

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/ratio-bots/{$config->id}/recalculate-resume", [
                'stage_buy_allocations' => [40, 30, 30],
                'stage_buy_drops' => [8, 6, 6],
                'stage_sell_allocations' => [30, 30, 40],
                'stage_sell_rises' => [4, 6, 8],
                'apply_mode' => 'next_stage',
            ]);

        $response->assertOk();
        $config->refresh();
        $this->assertSame([40, 30, 30], $config->stage_buy_allocations);
        $this->assertSame([30, 30, 40], $config->stage_sell_allocations);
        $this->assertNotSame('paused', $config->state()->first()->position_state);
    }

    /** @test */
    public function can_stop_bot(): void
    {
        $config = $this->createConfig();
        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/start");

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/ratio-bots/{$config->id}/stop");

        $response->assertOk();
        $this->assertDatabaseHas('ratio_bot_states', [
            'ratio_bot_config_id' => $config->id,
            'position_state'      => 'idle',
        ]);
        $this->assertDatabaseHas('ratio_bot_configs', ['id' => $config->id, 'enabled' => false]);
    }

    // -----------------------------------------------------------------------
    // Manual buy anchor
    // -----------------------------------------------------------------------

    /** @test */
    public function anchor_buy_transitions_bot_to_holding(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/ratio-bots/{$config->id}/anchor-buy", [
                'buy_price' => 60000.0,
                'quantity'  => 0.001,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('ratio_bot_states', [
            'ratio_bot_config_id' => $config->id,
            'position_state'      => 'holding',
        ]);
    }

    /** @test */
    public function anchor_buy_emits_event(): void
    {
        $config = $this->createConfig();

        $this->actingAs($this->user)
            ->postJson("/api/v1/ratio-bots/{$config->id}/anchor-buy", [
                'buy_price' => 60000.0,
                'quantity'  => 0.001,
            ]);

        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type'          => 'manual_buy_anchored',
        ]);
    }

    /** @test */
    public function anchor_sell_transitions_bot_to_flat(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/ratio-bots/{$config->id}/anchor-sell", [
                'sell_price' => 90000.0,
                'quantity'   => 0.001,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('ratio_bot_states', [
            'ratio_bot_config_id' => $config->id,
            'position_state'      => 'flat',
        ]);
        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type'          => 'manual_sell_anchored',
        ]);
    }

    // -----------------------------------------------------------------------
    // State and ratio endpoints
    // -----------------------------------------------------------------------

    /** @test */
    public function state_endpoint_returns_current_state(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/ratio-bots/{$config->id}/state");

        $response->assertOk()->assertJsonPath('position_state', 'idle');
    }

    /** @test */
    public function decisions_endpoint_returns_audit_trail(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/ratio-bots/{$config->id}/decisions");

        $response->assertOk()->assertJsonIsArray();
    }

    /** @test */
    public function events_endpoint_returns_event_log(): void
    {
        $config = $this->createConfig();
        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/start");

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/ratio-bots/{$config->id}/events");

        $response->assertOk();
        $events = $response->json();
        $types = collect($events)->pluck('event_type')->all();
        $this->assertContains('started', $types);
    }

    /** @test */
    public function evaluate_endpoint_dispatches_job(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $config = $this->createConfig();

        $this->actingAs($this->user)
            ->postJson("/api/v1/ratio-bots/{$config->id}/evaluate")
            ->assertOk();

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunRatioBotEvaluationJob::class);
    }

    // -----------------------------------------------------------------------
    // Failure safety
    // -----------------------------------------------------------------------

    /** @test */
    public function cannot_create_config_without_symbol(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ratio-bots", [
                'sell_threshold_ratio' => 0.50,
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['symbol']);
    }

    /** @test */
    public function delete_also_removes_state_and_decisions(): void
    {
        $config = $this->createConfig();
        // Anchor buy to create state + event
        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/anchor-buy", [
            'buy_price' => 60000.0,
            'quantity'  => 0.001,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/ratio-bots/{$config->id}")
            ->assertOk();

        $this->assertDatabaseMissing('ratio_bot_configs', ['id' => $config->id]);
        $this->assertDatabaseMissing('ratio_bot_states', ['ratio_bot_config_id' => $config->id]);
    }

    // -----------------------------------------------------------------------
    // Master Brain (Phase 1)
    // -----------------------------------------------------------------------

    /** @test */
    public function brain_snapshot_endpoint_returns_empty_shape_when_none_exists(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/ratio-bots/{$config->id}/brain-snapshot");

        $response->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('signal', null)
            ->assertJsonPath('forecast', null);
    }

    /** @test */
    public function brain_snapshot_endpoint_returns_latest_row_when_present(): void
    {
        $config = $this->createConfig(['brain_enabled' => true, 'timeframe' => '1h']);
        \App\Models\RatioBotBrainSnapshot::create([
            'ratio_bot_config_id' => $config->id,
            'symbol' => 'BTCUSDT',
            'timeframe' => '1h',
            'horizon_seconds' => 3600,
            'direction' => 'buy',
            'confidence' => 72,
            'forecast_bias' => 'BUY',
            'forecast_confidence' => 60,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/ratio-bots/{$config->id}/brain-snapshot");

        $response->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('signal.direction', 'buy')
            ->assertJsonPath('signal.confidence', 72)
            ->assertJsonPath('forecast.horizon_seconds', 3600)
            ->assertJsonPath('forecast.bias', 'BUY');
    }

    /** @test */
    public function reserve_tranche_fields_are_accepted_and_persisted(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/ratio-bots/{$config->id}", [
                'initial_entry_pct' => 0.6,
                'reserve_tranche_count' => 2,
            ]);

        $response->assertOk()
            ->assertJsonPath('initial_entry_pct', '0.6000')
            ->assertJsonPath('reserve_tranche_count', 2);
    }

    /** @test */
    public function timeframe_accepts_1h_and_4h_for_brain_horizon(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/ratio-bots/{$config->id}", [
                'timeframe' => '4h',
                'brain_enabled' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('timeframe', '4h')
            ->assertJsonPath('brain_enabled', true);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function createConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id'        => $this->workspace->id,
            'symbol'              => 'BTCUSDT',
            'sell_threshold_ratio'=> 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount'   => 100.0,
            'execution_mode'      => 'sandbox',
        ], $overrides));

        // ensure state row
        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_IDLE]
        );

        return $config->refresh();
    }
}
