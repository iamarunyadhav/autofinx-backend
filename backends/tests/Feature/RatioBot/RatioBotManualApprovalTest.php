<?php

namespace Tests\Feature\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Spec §51 Assisted/Manual mode: same decision engine as automatic mode
 * (RatioBotRealtimeEngine tags the decision with requires_manual_approval
 * instead of maintaining a second algorithm), execution just waits for an
 * explicit operator approve/reject.
 */
class RatioBotManualApprovalTest extends TestCase
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
        Queue::fake();
    }

    /** @test */
    public function pending_approval_returns_the_latest_awaiting_suggestion(): void
    {
        $config = $this->createConfig();
        $this->makePendingDecision($config, RatioBotDecision::DECISION_BUY, 60000);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/pending-approval");

        $response->assertOk()->assertJsonPath('decision', 'BUY');
    }

    /** @test */
    public function pending_approval_is_null_once_resolved(): void
    {
        $config = $this->createConfig();
        $decision = $this->makePendingDecision($config, RatioBotDecision::DECISION_BUY, 60000);

        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/decisions/{$decision->id}/reject")->assertOk();

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/pending-approval");
        $response->assertOk();
        // response()->json(null) serializes as {} in this app, not a literal
        // JSON null — assert emptiness rather than a specific PHP type.
        $this->assertEmpty($response->json());
    }

    /** @test */
    public function approving_a_sandbox_buy_suggestion_executes_it_through_the_same_pipeline_as_auto_mode(): void
    {
        $config = $this->createConfig(['execution_mode' => 'sandbox', 'base_quote_amount' => 100]);
        $decision = $this->makePendingDecision($config, RatioBotDecision::DECISION_BUY, 60000);

        $response = $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/decisions/{$decision->id}/approve");

        $response->assertOk();
        $decision->refresh();
        $this->assertSame(RatioBotDecision::EXEC_FILLED, $decision->execution_status);
        $this->assertSame('approved', $decision->metadata['manual_decision']);
        $this->assertSame(RatioBotState::STATE_HOLDING, $config->fresh('state')->state->position_state);
    }

    /** @test */
    public function cannot_approve_a_decision_that_does_not_require_approval(): void
    {
        $config = $this->createConfig();
        $decision = RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => RatioBotDecision::DECISION_BUY,
            'evaluated_price' => 60000,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'decided_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/decisions/{$decision->id}/approve");

        $response->assertStatus(422);
    }

    /** @test */
    public function cannot_approve_an_expired_suggestion(): void
    {
        $config = $this->createConfig(['execution_mode' => 'sandbox', 'base_quote_amount' => 100]);
        $decision = $this->makePendingDecision($config, RatioBotDecision::DECISION_BUY, 60000, now()->subMinutes(20));

        $response = $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/decisions/{$decision->id}/approve");

        $response->assertStatus(422);
        $decision->refresh();
        $this->assertNotSame(RatioBotDecision::EXEC_FILLED, $decision->execution_status);
    }

    /** @test */
    public function cannot_approve_the_same_decision_twice(): void
    {
        $config = $this->createConfig(['execution_mode' => 'sandbox', 'base_quote_amount' => 100]);
        $decision = $this->makePendingDecision($config, RatioBotDecision::DECISION_BUY, 60000);

        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/decisions/{$decision->id}/approve")->assertOk();
        $second = $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/decisions/{$decision->id}/approve");

        $second->assertStatus(422);
    }

    private function makePendingDecision(RatioBotConfig $config, string $side, float $price, $decidedAt = null): RatioBotDecision
    {
        return RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => $side,
            'evaluated_price' => $price,
            'anchor_price' => $price,
            'execution_status' => RatioBotDecision::EXEC_SKIPPED,
            'reason' => 'mode=manual_approval requires manual approval',
            'metadata' => ['requires_manual_approval' => true, 'manual_decision' => null],
            'decided_at' => $decidedAt ?? now(),
        ]);
    }

    private function createConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'mode' => 'manual_approval',
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_FLAT]
        );

        return $config->refresh()->load('state');
    }
}
