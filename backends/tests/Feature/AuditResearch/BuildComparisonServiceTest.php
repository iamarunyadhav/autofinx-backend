<?php

namespace Tests\Feature\AuditResearch;

use App\Domain\AuditResearch\BuildComparisonService;
use App\Models\AuditIncident;
use App\Models\AuditSession;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuildComparisonServiceTest extends TestCase
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
    }

    private function makeSessionWithDecisions(Workspace $workspace, string $symbol, array $decisions): AuditSession
    {
        $bot = RatioBotConfig::create([
            'workspace_id' => $workspace->id,
            'symbol' => $symbol,
            'execution_mode' => 'sandbox',
        ]);

        $session = AuditSession::create([
            'workspace_id' => $workspace->id,
            'name' => "Session {$symbol}",
            'bot_type' => 'ratio_bot',
            'bot_instance_id' => $bot->id,
            'environment' => 'sandbox',
            'symbol' => $symbol,
            'mode' => 'standard',
            'status' => 'running',
            'started_at' => now()->subHour(),
        ]);

        foreach ($decisions as $d) {
            RatioBotDecision::create([
                'id' => (string) Str::uuid(),
                'ratio_bot_config_id' => $bot->id,
                'decision' => $d['decision'],
                'execution_status' => $d['execution_status'] ?? null,
                'decided_at' => now(),
            ]);
        }

        return $session;
    }

    /** @test */
    public function two_sessions_with_different_real_data_produce_a_genuinely_different_comparison(): void
    {
        $sessionA = $this->makeSessionWithDecisions($this->workspace, 'BTCUSDT', [
            ['decision' => 'BUY', 'execution_status' => 'filled'],
            ['decision' => 'BUY', 'execution_status' => 'filled'],
        ]);

        $sessionB = $this->makeSessionWithDecisions($this->workspace, 'ETHUSDT', [
            ['decision' => 'BUY', 'execution_status' => 'failed'],
            ['decision' => 'SELL', 'execution_status' => 'failed'],
            ['decision' => 'SELL', 'execution_status' => 'filled'],
        ]);

        AuditIncident::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'bot_instance_id' => $sessionB->bot_instance_id,
            'title' => 'Repeated order failures',
            'root_cause_category' => 'ORDER_EXECUTION_ERROR',
            'severity' => 'high',
            'status' => 'persistent',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ]);

        $result = app(BuildComparisonService::class)->compare($sessionA->fresh(), $sessionB->fresh());

        $this->assertSame(0, $result['execution_failure_count']['session_a']);
        $this->assertSame(2, $result['execution_failure_count']['session_b']);
        $this->assertSame(0, $result['total_incidents']['session_a']);
        $this->assertSame(1, $result['total_incidents']['session_b']);
        $this->assertSame(1, $result['root_cause_breakdown']['session_b']['ORDER_EXECUTION_ERROR']);
        $this->assertSame(0, $result['root_cause_breakdown']['session_a']['ORDER_EXECUTION_ERROR']);
    }

    /** @test */
    public function comparing_sessions_from_different_workspaces_via_the_service_throws(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $sessionA = $this->makeSessionWithDecisions($this->workspace, 'BTCUSDT', []);
        $sessionB = $this->makeSessionWithDecisions($otherWorkspace, 'ETHUSDT', []);

        $this->expectException(\RuntimeException::class);
        app(BuildComparisonService::class)->compare($sessionA, $sessionB);
    }

    /** @test */
    public function the_compare_route_rejects_a_session_from_a_different_workspace_with_403(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $sessionA = $this->makeSessionWithDecisions($this->workspace, 'BTCUSDT', []);
        $sessionB = $this->makeSessionWithDecisions($otherWorkspace, 'ETHUSDT', []);

        $this->actingAs($this->user)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-research/compare?session_a={$sessionA->id}&session_b={$sessionB->id}")
            ->assertStatus(403);
    }

    /** @test */
    public function the_compare_route_succeeds_for_two_sessions_in_the_same_workspace(): void
    {
        $sessionA = $this->makeSessionWithDecisions($this->workspace, 'BTCUSDT', []);
        $sessionB = $this->makeSessionWithDecisions($this->workspace, 'ETHUSDT', []);

        $this->actingAs($this->user)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-research/compare?session_a={$sessionA->id}&session_b={$sessionB->id}")
            ->assertOk()
            ->assertJsonStructure(['session_a', 'session_b', 'prediction_accuracy', 'execution_failure_count', 'average_decision_latency_ms', 'root_cause_breakdown']);
    }
}
