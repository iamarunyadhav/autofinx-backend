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

/**
 * Headline regression-proof test for this task: a user who is a member of
 * workspace A must not be able to start/pause/resume/stop/kill-switch/
 * update/delete (or even read) a Ratio Bot that belongs to workspace B, even
 * though every route only encodes {ratioBot} in the URL (no {workspace}
 * segment on the mutating lifecycle routes). Mirrors
 * ForexGridBotControllerIdorTest's structure/style, extended to cover this
 * controller's much broader route surface (46 methods, config CRUD,
 * lifecycle, manual anchors, manual-approval decisions, and every read-only
 * telemetry endpoint).
 */
class RatioBotControllerIdorTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;
    private Workspace $workspaceA;
    private Workspace $workspaceB;
    private RatioBotConfig $botInWorkspaceB;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->userA = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $this->workspaceA = Workspace::factory()->create();
        $this->workspaceB = Workspace::factory()->create();

        WorkspaceMember::create([
            'workspace_id' => $this->workspaceA->id,
            'user_id' => $this->userA->id,
            'role' => 'owner',
        ]);
        // Deliberately NOT a member of workspaceB.

        $this->botInWorkspaceB = $this->makeConfig($this->workspaceB->id);
    }

    private function makeConfig(string $workspaceId): RatioBotConfig
    {
        $config = RatioBotConfig::create([
            'workspace_id' => $workspaceId,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'timeframe' => '1m',
            'algorithm' => 'ratio_threshold',
            'execution_mode' => 'sandbox',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'enabled' => true,
        ]);

        RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_IDLE,
            'feed_status' => 'healthy',
        ]);

        return $config->fresh('state');
    }

    // -----------------------------------------------------------------------
    // index/store — workspace-scoped, not bot-scoped
    // -----------------------------------------------------------------------

    /** @test */
    public function cannot_list_bots_in_a_workspace_the_user_is_not_a_member_of(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/workspaces/{$this->workspaceB->id}/ratio-bots")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_create_a_bot_in_a_workspace_the_user_is_not_a_member_of(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/workspaces/{$this->workspaceB->id}/ratio-bots", [
                'symbol' => 'ETHUSDT',
                'sell_threshold_ratio' => 0.02,
                'buy_threshold_ratio' => 0.01,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('ratio_bot_configs', [
            'workspace_id' => $this->workspaceB->id,
            'symbol' => 'ETHUSDT',
        ]);
    }

    // -----------------------------------------------------------------------
    // Mutating lifecycle routes
    // -----------------------------------------------------------------------

    /** @test */
    public function cannot_start_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/start")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_pause_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/pause")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_resume_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/resume")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_stop_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/stop")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_recalculate_resume_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/recalculate-resume", [])
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_anchor_buy_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/anchor-buy", [
                'buy_price' => 100,
                'quantity' => 1,
            ])
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_anchor_sell_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/anchor-sell", [
                'sell_price' => 100,
                'quantity' => 1,
            ])
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_anchor_starting_inventory_for_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/anchor-starting-inventory", [
                'cost_basis_price' => 100,
                'quantity' => 1,
            ])
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_evaluate_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/evaluate")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_kill_switch_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/kill-switch", ['kill_switch' => true])
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_update_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->putJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}", ['sell_threshold_ratio' => 0.05])
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_delete_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->deleteJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('ratio_bot_configs', ['id' => $this->botInWorkspaceB->id]);
    }

    // -----------------------------------------------------------------------
    // Read-only routes — config read, state read, financial data read,
    // event/history read.
    // -----------------------------------------------------------------------

    /** @test */
    public function cannot_read_config_of_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_state_of_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/state")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_status_of_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/status")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_wallet_of_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/wallet")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_lots_of_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/lots")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_pnl_summary_of_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/pnl-summary")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_position_of_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/position")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_decisions_of_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/decisions")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_events_of_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/events")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_diagnostics_of_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/diagnostics")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_apply_a_diagnostics_fix_to_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/diagnostics/fix", ['fix_key' => 'anything'])
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------------
    // Legitimate same-workspace access must be completely unaffected.
    // -----------------------------------------------------------------------

    /** @test */
    public function a_member_of_the_owning_workspace_can_read_the_bots_state(): void
    {
        $memberOfB = User::factory()->create(['two_factor_confirmed_at' => now()]);
        WorkspaceMember::create([
            'workspace_id' => $this->workspaceB->id,
            'user_id' => $memberOfB->id,
            'role' => 'trader',
        ]);

        $this->actingAs($memberOfB)
            ->getJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/state")
            ->assertStatus(200);
    }

    /** @test */
    public function a_member_of_the_owning_workspace_can_pause_the_bot(): void
    {
        $memberOfB = User::factory()->create(['two_factor_confirmed_at' => now()]);
        WorkspaceMember::create([
            'workspace_id' => $this->workspaceB->id,
            'user_id' => $memberOfB->id,
            'role' => 'admin',
        ]);

        $this->actingAs($memberOfB)
            ->postJson("/api/v1/ratio-bots/{$this->botInWorkspaceB->id}/pause")
            ->assertStatus(200);
    }

    /** @test */
    public function a_member_of_the_owning_workspace_can_list_bots_in_that_workspace(): void
    {
        $memberOfB = User::factory()->create(['two_factor_confirmed_at' => now()]);
        WorkspaceMember::create([
            'workspace_id' => $this->workspaceB->id,
            'user_id' => $memberOfB->id,
            'role' => 'owner',
        ]);

        $this->actingAs($memberOfB)
            ->getJson("/api/v1/workspaces/{$this->workspaceB->id}/ratio-bots")
            ->assertStatus(200)
            ->assertJsonCount(1);
    }
}
