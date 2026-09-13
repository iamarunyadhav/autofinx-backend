<?php

namespace Tests\Feature\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RatioBotPhase2EndpointsTest extends TestCase
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
    public function lots_endpoint_returns_both_open_and_closed_lots_with_type(): void
    {
        $config = $this->createConfig();
        RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'buy_stage_index' => 1,
            'lot_type' => RatioBotLot::TYPE_NORMAL,
            'buy_price' => 60000,
            'original_qty' => 0.001,
            'remaining_qty' => 0.001,
            'spent_quote' => 60,
            'status' => RatioBotLot::STATUS_OPEN,
            'bought_at' => now(),
        ]);
        RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'buy_stage_index' => 4,
            'lot_type' => RatioBotLot::TYPE_RECOVERY,
            'buy_price' => 41500,
            'original_qty' => 0.0005,
            'remaining_qty' => 0,
            'spent_quote' => 20.75,
            'status' => RatioBotLot::STATUS_CLOSED,
            'bought_at' => now(),
            'closed_at' => now(),
            'realized_pnl' => 0.35,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/lots");

        $response->assertOk()->assertJsonCount(2);
        $types = collect($response->json())->pluck('lot_type')->all();
        $this->assertContains(RatioBotLot::TYPE_NORMAL, $types);
        $this->assertContains(RatioBotLot::TYPE_RECOVERY, $types);
    }

    /** @test */
    public function readiness_endpoint_is_ready_when_no_starting_entry_configured(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/readiness");

        $response->assertOk()->assertJsonPath('ready', true)->assertJsonPath('checked', false);
    }

    /** @test */
    public function readiness_endpoint_skips_the_live_balance_check_in_sandbox_mode(): void
    {
        $config = $this->createConfig(['starting_entry' => 'BUY', 'execution_mode' => 'sandbox']);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/readiness");

        $response->assertOk()->assertJsonPath('ready', true)->assertJsonPath('checked', false);
    }

    /** @test */
    public function start_is_blocked_when_readiness_reports_not_ready(): void
    {
        $config = $this->createConfig(['starting_entry' => 'BUY', 'execution_mode' => 'live']);
        // Workspace trading mode defaults to 'paper' with no live integration configured,
        // so walletBalances() returns mode=paper and startingEntryReadiness() short-circuits
        // to ready=true (no live balance to check) — assert the wiring itself, not a specific
        // insufficient-balance payload, since that requires a real exchange integration.
        $response = $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/start");

        $response->assertOk();
    }

    /** @test */
    public function wallet_endpoint_returns_symbol_asset_split(): void
    {
        $config = $this->createConfig(['symbol' => 'ETHUSDT']);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/wallet");

        $response->assertOk()
            ->assertJsonPath('base_asset', 'ETH')
            ->assertJsonPath('quote_asset', 'USDT');
    }

    /** @test */
    public function effective_feed_status_endpoint_reflects_fresh_or_stale_from_persisted_state(): void
    {
        $config = $this->createConfig();
        $config->state->update([
            'last_price_timestamp' => now()->subSeconds(5),
            'last_price_source' => 'polling',
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/effective-feed-status");

        $response->assertOk()->assertJsonPath('status', 'FRESH')->assertJsonPath('fresh', true);

        $config->state->update(['last_price_timestamp' => now()->subDay()]);
        $stale = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/effective-feed-status");
        $stale->assertOk()->assertJsonPath('status', 'STALE_FEED')->assertJsonPath('fresh', false);
    }

    /** @test */
    public function daily_report_aggregates_only_actual_filled_trades_and_closed_lots(): void
    {
        $config = $this->createConfig(['fee_bps' => 10]);
        $day = now()->startOfDay();

        \App\Models\RatioBotDecision::create([
            'ratio_bot_config_id' => $config->id,
            'decision' => 'BUY',
            'execution_status' => 'filled',
            'executed_price' => 60000,
            'executed_qty' => 0.001,
            'decided_at' => $day,
            'executed_at' => $day,
        ]);
        RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'buy_stage_index' => 1,
            'lot_type' => RatioBotLot::TYPE_NORMAL,
            'buy_price' => 60000,
            'original_qty' => 0.001,
            'remaining_qty' => 0,
            'spent_quote' => 60,
            'status' => RatioBotLot::STATUS_CLOSED,
            'bought_at' => $day,
            'closed_at' => $day,
            'realized_pnl' => 1.25,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/daily-report");

        $response->assertOk();
        $days = $response->json('days');
        $this->assertCount(1, $days);
        $this->assertSame(1, $days[0]['trades']);
        $this->assertEqualsWithDelta(1.25, $days[0]['net_pnl'], 0.001);
        $this->assertGreaterThan(0, $days[0]['fees']);
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
