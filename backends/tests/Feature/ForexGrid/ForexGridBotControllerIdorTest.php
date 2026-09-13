<?php

namespace Tests\Feature\ForexGrid;

use App\Models\ForexGridBotConfig;
use App\Models\ForexGridBotState;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Headline regression-proof test for this task: a user who is a member of
 * workspace A must not be able to start/pause/stop/kill-switch/update/
 * delete (or even read) a Forex Grid bot that belongs to workspace B, even
 * though every route only encodes {forexGridBot} in the URL (no
 * {workspace} segment on the mutating lifecycle routes). This is the exact
 * IDOR shape independently confirmed (via grep, not fixed) to exist today
 * on RatioBotController's equivalent routes.
 */
class ForexGridBotControllerIdorTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;
    private Workspace $workspaceA;
    private Workspace $workspaceB;
    private ForexGridBotConfig $botInWorkspaceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $this->workspaceA = Workspace::factory()->create();
        $this->workspaceB = Workspace::factory()->create();

        WorkspaceMember::create([
            'workspace_id' => $this->workspaceA->id,
            'user_id' => $this->userA->id,
            'role' => 'owner',
        ]);
        // Deliberately NOT a member of workspaceB.

        $this->botInWorkspaceB = ForexGridBotConfig::create([
            'workspace_id' => $this->workspaceB->id,
            'symbol' => 'EURUSD',
            'grid_spacing_pips' => 20,
            'position_sizing_mode' => 'fixed',
            'base_lot_size' => 0.10,
            'max_grid_levels' => 8,
            'max_total_exposure_lots' => 5,
            'basket_stop_loss_pct' => 5,
            'enabled' => true,
        ]);
        ForexGridBotState::create([
            'forex_grid_bot_config_id' => $this->botInWorkspaceB->id,
            'status' => ForexGridBotState::STATUS_RUNNING,
        ]);
    }

    /** @test */
    public function cannot_start_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/forex-grid-bots/{$this->botInWorkspaceB->id}/start")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_pause_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/forex-grid-bots/{$this->botInWorkspaceB->id}/pause")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_stop_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/forex-grid-bots/{$this->botInWorkspaceB->id}/stop")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_kill_switch_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/forex-grid-bots/{$this->botInWorkspaceB->id}/kill-switch", ['reason' => 'test'])
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_update_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->putJson("/api/v1/forex-grid-bots/{$this->botInWorkspaceB->id}", ['grid_spacing_pips' => 99])
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_delete_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->deleteJson("/api/v1/forex-grid-bots/{$this->botInWorkspaceB->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('forex_grid_bot_configs', ['id' => $this->botInWorkspaceB->id]);
    }

    /** @test */
    public function cannot_read_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/forex-grid-bots/{$this->botInWorkspaceB->id}")
            ->assertStatus(403);

        $this->actingAs($this->userA)
            ->getJson("/api/v1/forex-grid-bots/{$this->botInWorkspaceB->id}/levels")
            ->assertStatus(403);

        $this->actingAs($this->userA)
            ->getJson("/api/v1/forex-grid-bots/{$this->botInWorkspaceB->id}/events")
            ->assertStatus(403);
    }

    /** @test */
    public function a_member_of_the_owning_workspace_can_perform_the_same_actions(): void
    {
        $memberOfB = User::factory()->create(['two_factor_confirmed_at' => now()]);
        WorkspaceMember::create([
            'workspace_id' => $this->workspaceB->id,
            'user_id' => $memberOfB->id,
            'role' => 'owner',
        ]);

        $this->actingAs($memberOfB)
            ->postJson("/api/v1/forex-grid-bots/{$this->botInWorkspaceB->id}/pause")
            ->assertStatus(200);
    }
}
