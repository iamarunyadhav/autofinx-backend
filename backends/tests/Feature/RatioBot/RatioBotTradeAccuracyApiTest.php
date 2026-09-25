<?php

namespace Tests\Feature\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\RatioBotTradeOutcome;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Read-only trade-accuracy endpoints: workspace-membership auth (IDOR) and
 * the summary/recent payload shape.
 */
class RatioBotTradeAccuracyApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $mine;
    private Workspace $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $this->mine = Workspace::factory()->create();
        $this->theirs = Workspace::factory()->create();
        WorkspaceMember::create(['workspace_id' => $this->mine->id, 'user_id' => $this->user->id, 'role' => 'viewer']);
    }

    /** @test */
    public function member_gets_bot_summary_and_recent_rows(): void
    {
        config(['ratio_bot_outcomes.min_samples_for_accuracy' => 1]);
        $bot = $this->makeBot($this->mine->id);
        $this->outcome($bot, 'BUY', 'evaluated', true, 'sandbox');
        $this->outcome($bot, 'BUY', 'pending', null, 'sandbox');
        $this->outcome($bot, 'SELL', 'evaluated', false, 'live');

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/ratio-bots/{$bot->id}/trade-accuracy")
            ->assertOk()
            ->assertJsonPath('bot_id', (string) $bot->id)
            ->assertJsonPath('summary.buy.captured', 2)
            ->assertJsonPath('summary.buy.evaluated', 1)
            ->assertJsonPath('summary.buy.pending', 1)
            ->assertJsonPath('summary.buy.good_pct', 100)
            ->assertJsonPath('summary.sell.sold_early_count', 1);

        $this->assertCount(3, $response->json('recent'));

        $this->actingAs($this->user)
            ->getJson("/api/v1/ratio-bots/{$bot->id}/trade-accuracy?mode=live")
            ->assertOk()
            ->assertJsonPath('mode', 'live')
            ->assertJsonPath('summary.buy.captured', 0)
            ->assertJsonPath('summary.sell.captured', 1);
    }

    /** @test */
    public function member_gets_workspace_summary_with_per_bot_breakdown(): void
    {
        $a = $this->makeBot($this->mine->id);
        $b = $this->makeBot($this->mine->id);
        $foreign = $this->makeBot($this->theirs->id);
        $this->outcome($a, 'BUY', 'evaluated', true);
        $this->outcome($b, 'SELL', 'evaluated', true);
        $this->outcome($foreign, 'BUY', 'evaluated', false);

        $this->actingAs($this->user)
            ->getJson("/api/v1/workspaces/{$this->mine->id}/ratio-bots/trade-accuracy")
            ->assertOk()
            ->assertJsonPath('summary.buy.captured', 1)
            ->assertJsonPath('summary.sell.captured', 1)
            ->assertJsonCount(2, 'per_bot');
    }

    /** @test */
    public function non_member_cannot_read_another_workspaces_accuracy(): void
    {
        $foreign = $this->makeBot($this->theirs->id);

        $this->actingAs($this->user)
            ->getJson("/api/v1/ratio-bots/{$foreign->id}/trade-accuracy")
            ->assertStatus(403);

        $this->actingAs($this->user)
            ->getJson("/api/v1/workspaces/{$this->theirs->id}/ratio-bots/trade-accuracy")
            ->assertStatus(403);
    }

    /** @test */
    public function guests_are_rejected_and_bad_mode_is_a_validation_error(): void
    {
        $bot = $this->makeBot($this->mine->id);

        $this->getJson("/api/v1/ratio-bots/{$bot->id}/trade-accuracy")->assertStatus(401);

        $this->actingAs($this->user)
            ->getJson("/api/v1/ratio-bots/{$bot->id}/trade-accuracy?mode=paper")
            ->assertStatus(422);
    }

    private function makeBot(string $workspaceId): RatioBotConfig
    {
        $config = RatioBotConfig::create([
            'workspace_id' => $workspaceId,
            'name' => 'accuracy-test-' . Str::random(8),
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
        RatioBotState::create(['ratio_bot_config_id' => $config->id, 'position_state' => RatioBotState::STATE_IDLE]);

        return $config;
    }

    private function outcome(RatioBotConfig $bot, string $side, string $status, ?bool $correct, string $mode = 'sandbox'): RatioBotTradeOutcome
    {
        return RatioBotTradeOutcome::create([
            'ratio_bot_config_id' => $bot->id,
            'ratio_bot_decision_id' => (string) Str::uuid(),
            'side' => $side,
            'execution_mode' => $mode,
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'timeframe' => '1m',
            'executed_price' => 100,
            'executed_qty' => 1,
            'executed_at' => now(),
            'horizon_seconds' => 3600,
            'evaluation_status' => $status,
            'decision_correct' => $correct,
            'verdict' => $correct === null ? null : ($side === 'BUY'
                ? ($correct ? RatioBotTradeOutcome::VERDICT_BUY_RECOVERED : RatioBotTradeOutcome::VERDICT_BUY_NOT_RECOVERED)
                : ($correct ? RatioBotTradeOutcome::VERDICT_SELL_WELL_TIMED : RatioBotTradeOutcome::VERDICT_SELL_EARLY)),
        ]);
    }
}
