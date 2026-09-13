<?php

namespace Tests\Feature\AuditResearch;

use App\Domain\RatioBot\RatioBotDecisionService;
use App\Models\AuditEvent;
use App\Models\AuditSession;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditResearchSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
    private RatioBotConfig $bot;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit_research.enabled' => true]);

        $this->user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $this->workspace = Workspace::factory()->create();
        WorkspaceMember::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'role' => 'owner',
        ]);

        $this->bot = RatioBotConfig::create([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
        ]);
        RatioBotState::create([
            'ratio_bot_config_id' => $this->bot->id,
            'position_state' => RatioBotState::STATE_FLAT,
            'last_sell_price' => 90_000,
            'last_sell_quantity' => 1.0,
            'active_anchor_price' => 90_000,
            'active_anchor_type' => 'sell_anchor',
        ]);
    }

    private function createSession(): AuditSession
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/audit-research/sessions", [
                'name' => 'Live smoke test',
                'bot_instance_id' => $this->bot->id,
                'environment' => 'sandbox',
                'symbol' => 'BTCUSDT',
            ])
            ->assertStatus(201)
            ->json();

        return AuditSession::find($response['id']);
    }

    /** @test */
    public function a_session_can_be_created_and_stopped(): void
    {
        $session = $this->createSession();
        $this->assertSame('running', $session->status);

        $this->actingAs($this->user)
            ->postJson("/api/v1/audit-research/sessions/{$session->id}/stop")
            ->assertStatus(200)
            ->assertJsonPath('status', 'stopped');
    }

    /** @test */
    public function events_endpoint_supports_since_id_polling_cursor(): void
    {
        $session = $this->createSession();

        // Real decision ticks — this is real Ratio Bot code running in
        // sandbox mode, not fabricated data.
        $service = app(RatioBotDecisionService::class);
        $config = $this->bot->fresh('state');
        $service->evaluate($config, 72_000.0); // BUY-triggering tick

        // Attach the resulting audit events to this session for the test
        // (the session is an optional lens/filter — events are captured
        // always-on regardless of an active session id).
        AuditEvent::query()->update(['audit_session_id' => $session->id]);

        $first = $this->actingAs($this->user)
            ->getJson("/api/v1/audit-research/sessions/{$session->id}/events")
            ->assertStatus(200)
            ->json();

        $this->assertNotEmpty($first['events']);
        $sinceId = $first['next_since_id'];

        $second = $this->actingAs($this->user)
            ->getJson("/api/v1/audit-research/sessions/{$session->id}/events?since_id={$sinceId}")
            ->assertStatus(200)
            ->json();

        $this->assertEmpty($second['events']);
    }

    /** @test */
    public function report_and_all_three_exports_produce_non_empty_output_for_a_session_with_real_events(): void
    {
        $session = $this->createSession();

        $service = app(RatioBotDecisionService::class);
        $config = $this->bot->fresh('state');
        $service->evaluate($config, 72_000.0);

        AuditEvent::query()->update(['audit_session_id' => $session->id]);

        $report = $this->actingAs($this->user)
            ->getJson("/api/v1/audit-research/sessions/{$session->id}/report")
            ->assertStatus(200)
            ->json();

        $this->assertNotEmpty($report['events']);
        $this->assertNotEmpty($report['decisions']);

        $csv = $this->actingAs($this->user)
            ->get("/api/v1/audit-research/sessions/{$session->id}/export/csv")
            ->assertStatus(200)
            ->getContent();
        $this->assertNotEmpty($csv);

        $json = $this->actingAs($this->user)
            ->get("/api/v1/audit-research/sessions/{$session->id}/export/json")
            ->assertStatus(200)
            ->getContent();
        $this->assertNotEmpty($json);
        $this->assertIsArray(json_decode($json, true));

        $markdown = $this->actingAs($this->user)
            ->get("/api/v1/audit-research/sessions/{$session->id}/export/markdown")
            ->assertStatus(200)
            ->getContent();
        $this->assertNotEmpty($markdown);
        $this->assertStringContainsString('# Audit Research Report', $markdown);
    }

    /** @test */
    public function bot_instance_id_must_belong_to_the_same_workspace(): void
    {
        $otherWorkspace = Workspace::factory()->create();
        $foreignBot = RatioBotConfig::create([
            'workspace_id' => $otherWorkspace->id,
            'symbol' => 'ETHUSDT',
            'execution_mode' => 'sandbox',
        ]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/audit-research/sessions", [
                'name' => 'Bad session',
                'bot_instance_id' => $foreignBot->id,
            ])
            ->assertStatus(422);
    }
}
