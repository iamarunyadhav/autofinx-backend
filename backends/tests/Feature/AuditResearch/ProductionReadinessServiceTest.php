<?php

namespace Tests\Feature\AuditResearch;

use App\Domain\AuditResearch\ProductionReadinessService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotForecastBacktest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;
    private RatioBotConfig $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $this->bot = RatioBotConfig::create([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'execution_mode' => 'sandbox',
        ]);
    }

    /** @test */
    public function a_category_with_no_data_returns_insufficient_data_never_a_fabricated_pass(): void
    {
        $result = app(ProductionReadinessService::class)->evaluate($this->workspace->id, $this->bot->id);

        $this->assertSame('INSUFFICIENT_DATA', $result['categories']['prediction']['verdict']);
        $this->assertSame('INSUFFICIENT_DATA', $result['categories']['incident_handling']['verdict']);
        $this->assertSame('INSUFFICIENT_DATA', $result['overall']);
    }

    /** @test */
    public function a_healthy_seeded_prediction_sample_returns_pass(): void
    {
        RatioBotForecastBacktest::create([
            'id' => (string) Str::uuid(),
            'ratio_bot_config_id' => $this->bot->id,
            'horizon_seconds' => 3600,
            'sample_size' => 100,
            'hits' => 70,
            'hit_rate_pct' => 70.0,
            'computed_at' => now(),
        ]);

        $result = app(ProductionReadinessService::class)->evaluate($this->workspace->id, $this->bot->id);

        $this->assertSame('PASS', $result['categories']['prediction']['verdict']);
    }

    /** @test */
    public function an_unhealthy_seeded_prediction_sample_returns_fail(): void
    {
        RatioBotForecastBacktest::create([
            'id' => (string) Str::uuid(),
            'ratio_bot_config_id' => $this->bot->id,
            'horizon_seconds' => 3600,
            'sample_size' => 100,
            'hits' => 20,
            'hit_rate_pct' => 20.0,
            'computed_at' => now(),
        ]);

        $result = app(ProductionReadinessService::class)->evaluate($this->workspace->id, $this->bot->id);

        $this->assertSame('FAIL', $result['categories']['prediction']['verdict']);
    }

    /** @test */
    public function a_prediction_sample_below_the_documented_threshold_is_insufficient_data_not_a_guess(): void
    {
        RatioBotForecastBacktest::create([
            'id' => (string) Str::uuid(),
            'ratio_bot_config_id' => $this->bot->id,
            'horizon_seconds' => 3600,
            'sample_size' => ProductionReadinessService::MIN_PREDICTION_SAMPLE_SIZE - 1,
            'hits' => 1,
            'hit_rate_pct' => 90.0,
            'computed_at' => now(),
        ]);

        $result = app(ProductionReadinessService::class)->evaluate($this->workspace->id, $this->bot->id);

        $this->assertSame('INSUFFICIENT_DATA', $result['categories']['prediction']['verdict']);
    }

    /** @test */
    public function all_resolved_incidents_return_pass_while_a_persistent_incident_returns_fail(): void
    {
        \App\Models\AuditIncident::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'bot_instance_id' => $this->bot->id,
            'title' => 'Transient stale feed',
            'root_cause_category' => 'DATA_STALE',
            'severity' => 'low',
            'status' => 'resolved',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ]);

        $healthy = app(ProductionReadinessService::class)->evaluate($this->workspace->id, $this->bot->id);
        $this->assertSame('PASS', $healthy['categories']['incident_handling']['verdict']);

        \App\Models\AuditIncident::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'bot_instance_id' => $this->bot->id,
            'title' => 'Recurring order execution failure',
            'root_cause_category' => 'ORDER_EXECUTION_ERROR',
            'severity' => 'critical',
            'status' => 'persistent',
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ]);

        $unhealthy = app(ProductionReadinessService::class)->evaluate($this->workspace->id, $this->bot->id);
        $this->assertSame('FAIL', $unhealthy['categories']['incident_handling']['verdict']);
    }

    /** @test */
    public function the_database_category_is_always_a_real_live_check_never_insufficient_data(): void
    {
        $result = app(ProductionReadinessService::class)->evaluate($this->workspace->id, $this->bot->id);

        $this->assertSame('PASS', $result['categories']['database']['verdict']);
    }

    /** @test */
    public function the_readiness_route_is_idor_safe(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $otherWorkspace = Workspace::factory()->create();

        $this->actingAs($user)
            ->getJson("/api/v1/workspaces/{$otherWorkspace->id}/audit-research/readiness")
            ->assertStatus(403);

        WorkspaceMember::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $this->actingAs($user)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/audit-research/readiness")
            ->assertOk()
            ->assertJsonStructure(['categories', 'overall']);
    }
}
