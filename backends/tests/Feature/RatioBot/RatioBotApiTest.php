<?php

namespace Tests\Feature\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->workspace = Workspace::factory()->create([
            'user_id' => $this->user->id,
        ]);
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
