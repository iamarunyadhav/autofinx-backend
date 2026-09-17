<?php

namespace Tests\Feature\AuditResearch;

use App\Models\AuditSession;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mirrors RatioBotControllerIdorTest's structure: a user who is a member of
 * workspace A must not be able to read/mutate any audit-research resource
 * belonging to workspace B, even though the mutating/read routes below the
 * top-level workspace-scoped ones only encode {session} in the URL.
 */
class AuditResearchControllerIdorTest extends TestCase
{
    use RefreshDatabase;

    private User $userA;
    private Workspace $workspaceA;
    private Workspace $workspaceB;
    private AuditSession $sessionInWorkspaceB;

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

        $this->sessionInWorkspaceB = AuditSession::create([
            'workspace_id' => $this->workspaceB->id,
            'name' => 'Session B',
            'bot_type' => 'ratio_bot',
            'environment' => 'sandbox',
            'mode' => 'standard',
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /** @test */
    public function cannot_list_sessions_in_a_workspace_the_user_is_not_a_member_of(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/workspaces/{$this->workspaceB->id}/audit-research/sessions")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_create_a_session_in_a_workspace_the_user_is_not_a_member_of(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/workspaces/{$this->workspaceB->id}/audit-research/sessions", ['name' => 'x'])
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_a_session_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/audit-research/sessions/{$this->sessionInWorkspaceB->id}")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_stop_a_session_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->postJson("/api/v1/audit-research/sessions/{$this->sessionInWorkspaceB->id}/stop")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_events_of_a_session_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/audit-research/sessions/{$this->sessionInWorkspaceB->id}/events")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_decisions_of_a_session_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/audit-research/sessions/{$this->sessionInWorkspaceB->id}/decisions")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_incidents_of_a_session_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/audit-research/sessions/{$this->sessionInWorkspaceB->id}/incidents")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_report_of_a_session_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/audit-research/sessions/{$this->sessionInWorkspaceB->id}/report")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_export_csv_of_a_session_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/audit-research/sessions/{$this->sessionInWorkspaceB->id}/export/csv")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_export_json_of_a_session_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/audit-research/sessions/{$this->sessionInWorkspaceB->id}/export/json")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_export_markdown_of_a_session_belonging_to_another_workspace(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/audit-research/sessions/{$this->sessionInWorkspaceB->id}/export/markdown")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_workspace_incidents_of_a_workspace_the_user_is_not_a_member_of(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/workspaces/{$this->workspaceB->id}/audit-research/incidents")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_prediction_accuracy_of_a_workspace_the_user_is_not_a_member_of(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/workspaces/{$this->workspaceB->id}/audit-research/prediction-accuracy")
            ->assertStatus(403);
    }

    /** @test */
    public function cannot_read_the_forecast_shadow_report_of_a_workspace_the_user_is_not_a_member_of(): void
    {
        $this->actingAs($this->userA)
            ->getJson("/api/v1/workspaces/{$this->workspaceB->id}/audit-research/forecast-shadow-report")
            ->assertStatus(403);
    }

    /** @test */
    public function a_member_of_the_owning_workspace_can_read_the_session(): void
    {
        $memberOfB = User::factory()->create(['two_factor_confirmed_at' => now()]);
        WorkspaceMember::create([
            'workspace_id' => $this->workspaceB->id,
            'user_id' => $memberOfB->id,
            'role' => 'trader',
        ]);

        $this->actingAs($memberOfB)
            ->getJson("/api/v1/audit-research/sessions/{$this->sessionInWorkspaceB->id}")
            ->assertStatus(200);
    }
}
