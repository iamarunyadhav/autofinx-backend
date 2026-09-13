<?php

namespace Tests\Feature\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RatioBotV2ApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $this->workspace = Workspace::factory()->create();
        WorkspaceMember::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
        ]);

        // store()/update() dispatch BackfillMarketOhlcJob (real Binance REST
        // call) — fake the queue so these tests never make a live network call.
        Queue::fake();
    }

    /** @test */
    public function v2_enabled_defaults_to_false_on_create(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/ratio-bots", [
                'symbol' => 'BTCUSDT',
                'sell_threshold_ratio' => 0.50,
                'buy_threshold_ratio' => 0.25,
                'base_quote_amount' => 100.0,
            ]);

        $response->assertStatus(201);
        // The in-memory create() response doesn't reflect unset columns'
        // schema defaults (a pre-existing Eloquent characteristic, also true
        // of brain_enabled) — assert the actual persisted DB row instead,
        // which is the invariant that matters: v2 is never auto-enabled.
        $this->assertDatabaseHas('ratio_bot_configs', ['symbol' => 'BTCUSDT', 'v2_enabled' => false]);
    }

    /** @test */
    public function v2_and_staged_sell_and_recovery_fields_round_trip_through_update(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/ratio-bots/{$config->id}", [
                'v2_enabled' => true,
                'sell_tranche_count' => 2,
                'initial_exit_pct' => 0.5,
                'reserve_mode' => 'dynamic',
                'reserve_min_pct' => 0.1,
                'reserve_max_pct' => 0.4,
                'recovery_enabled' => true,
                'recovery_timeout_minutes' => 120,
                'max_entry_price_drift_bps' => 30,
            ]);

        $response->assertOk()
            ->assertJsonPath('v2_enabled', true)
            ->assertJsonPath('sell_tranche_count', 2)
            ->assertJsonPath('reserve_mode', 'dynamic')
            ->assertJsonPath('recovery_enabled', true)
            ->assertJsonPath('recovery_timeout_minutes', 120);
    }

    /** @test */
    public function invalid_reserve_mode_is_rejected(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/ratio-bots/{$config->id}", [
                'reserve_mode' => 'automagic',
            ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function snapshot_surfaces_partial_sold_and_recovery_states(): void
    {
        $config = $this->createConfig(['v2_enabled' => true]);
        $config->state->update(['position_state' => RatioBotState::STATE_PARTIAL_SOLD]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/state");

        $response->assertOk()->assertJsonPath('position_state', RatioBotState::STATE_PARTIAL_SOLD);
    }

    private function createConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_IDLE]
        );

        return $config->refresh()->load('state');
    }
}
