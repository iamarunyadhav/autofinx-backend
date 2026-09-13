<?php

namespace Tests\Feature\Pepperstone;

use App\Models\Instrument;
use App\Models\PepperstoneBotConfig;
use App\Models\PepperstoneBotState;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Headline regression-proof test for this module, mirroring
 * ForexGridBotControllerIdorTest's exact structure/intent: a user who is a
 * member of workspace A must not be able to start/pause/stop/kill-switch/
 * update/delete (or even read) a Pepperstone bot that belongs to workspace
 * B, even though every route only encodes {pepperstoneBot} in the URL (no
 * {workspace} segment on the mutating lifecycle routes).
 */
class PepperstoneBotControllerIdorTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;
    private Workspace $workspaceA;
    private Workspace $workspaceB;
    private PepperstoneBotConfig $botInWorkspaceB;

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

        $instrument = Instrument::create([
            'canonical_symbol' => 'XAUUSD',
            'market_type' => 'metal',
            'name' => 'Gold',
            'quote_currency' => 'USD',
            'enabled' => true,
        ]);

        $this->botInWorkspaceB = PepperstoneBotConfig::create([
            'workspace_id' => $this->workspaceB->id,
            'instrument_id' => $instrument->id,
            'position_sizing_mode' => 'fixed',
            'base_lot_size' => 0.10,
            'max_grid_levels' => 8,
            'max_total_exposure_lots' => 5,
            'max_position_lots' => 5,
            'basket_stop_loss_pct' => 5,
            'enabled' => true,
        ]);
        PepperstoneBotState::create([
            'pepperstone_bot_config_id' => $this->botInWorkspaceB->id,
            'status' => PepperstoneBotState::STATUS_RUNNING,
        ]);
    }

    /** @test */
    public function cannot_start_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/pepperstone-bots/{$this->botInWorkspaceB->id}/start")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_pause_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/pepperstone-bots/{$this->botInWorkspaceB->id}/pause")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_stop_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/pepperstone-bots/{$this->botInWorkspaceB->id}/stop")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_kill_switch_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/pepperstone-bots/{$this->botInWorkspaceB->id}/kill-switch", ['reason' => 'test'])
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_update_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->putJson("/api/v1/pepperstone-bots/{$this->botInWorkspaceB->id}", ['base_lot_size' => 0.50])
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_delete_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->deleteJson("/api/v1/pepperstone-bots/{$this->botInWorkspaceB->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('pepperstone_bot_configs', ['id' => $this->botInWorkspaceB->id]);
    }

    /** @test */
    public function cannot_read_a_bot_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/pepperstone-bots/{$this->botInWorkspaceB->id}")
            ->assertStatus(403);

        $this->actingAs($this->userA)
            ->getJson("/api/v1/pepperstone-bots/{$this->botInWorkspaceB->id}/positions")
            ->assertStatus(403);

        $this->actingAs($this->userA)
            ->getJson("/api/v1/pepperstone-bots/{$this->botInWorkspaceB->id}/events")
            ->assertStatus(403);

        $this->actingAs($this->userA)
            ->getJson("/api/v1/pepperstone-bots/{$this->botInWorkspaceB->id}/baskets")
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
            ->postJson("/api/v1/pepperstone-bots/{$this->botInWorkspaceB->id}/pause")
            ->assertStatus(200);
    }
}
