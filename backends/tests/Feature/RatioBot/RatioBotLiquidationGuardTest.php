<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBotRealtimeEngine;
use App\Domain\RatioBot\RatioBotRiskService;
use App\Domain\RatioBot\RatioBotStateService;
use App\Models\Position;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;
use App\Models\Symbol;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RatioBotLiquidationGuardTest extends TestCase
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
    public function spot_bot_is_never_applicable_regardless_of_position_data(): void
    {
        $config = $this->createConfig(['market_type' => 'spot', 'liquidation_guard_enabled' => true]);
        $this->createPosition($config, qty: -5.0, liquidationBufferPct: 1.0, syncedMinutesAgo: 1);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/liquidation-guard");

        $response->assertOk()->assertJsonPath('applicable', false)->assertJsonPath('danger_level', 'safe');
    }

    /** @test */
    public function futures_bot_with_no_open_position_is_safe(): void
    {
        $config = $this->createFuturesConfig();

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/liquidation-guard");

        $response->assertOk()
            ->assertJsonPath('applicable', true)
            ->assertJsonPath('danger_level', 'safe')
            ->assertJsonPath('action', 'none');
    }

    /** @test */
    public function buffer_above_watch_threshold_is_safe(): void
    {
        $config = $this->createFuturesConfig();
        $this->createPosition($config, qty: 1.5, liquidationBufferPct: 40.0, syncedMinutesAgo: 1);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/liquidation-guard");

        $response->assertOk()->assertJsonPath('danger_level', 'safe')->assertJsonPath('action', 'none');
    }

    /** @test */
    public function buffer_between_critical_and_watch_blocks_new_entries_but_not_a_protective_exit(): void
    {
        $config = $this->createFuturesConfig();
        $this->createPosition($config, qty: 1.5, liquidationBufferPct: 10.0, syncedMinutesAgo: 1);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/liquidation-guard");
        $response->assertOk()->assertJsonPath('danger_level', 'watch')->assertJsonPath('action', 'block_new_entries');

        $risk = app(RatioBotRiskService::class);

        $longDecision = RatioBotDecision::create($this->decisionAttrs($config, RatioBotDecision::DECISION_LONG));
        $block = $risk->preTradeBlockReason($config, $longDecision);
        $this->assertNotNull($block);
        $this->assertSame('liquidation_guard_block_new_entries', $block['code']);

        $exitDecision = RatioBotDecision::create($this->decisionAttrs($config, RatioBotDecision::DECISION_EXIT_LONG, ['protective_exit' => true]));
        $this->assertNull($risk->preTradeBlockReason($config, $exitDecision));
    }

    /** @test */
    public function buffer_at_or_below_critical_forces_a_protective_exit_via_the_engine(): void
    {
        $config = $this->createFuturesConfig(['mode' => 'restricted_auto']);
        $this->createPosition($config, qty: 2.0, liquidationBufferPct: 3.0, syncedMinutesAgo: 1);
        app(RatioBotStateService::class)->syncPositionSnapshot($config, 'long', 2.0, 50000.0, 0);

        $result = app(RatioBotRealtimeEngine::class)->evaluate($config->fresh('state'), 50000.0);

        $this->assertSame(RatioBotDecision::DECISION_EXIT_LONG, $result['decision']);
        $this->assertStringContainsString('liquidation_guard_force_exit', (string) $result['decision_record']->reason);
        $this->assertTrue((bool) data_get($result['decision_record']->metadata, 'protective_exit'));
        $this->assertDatabaseHas('ratio_bot_events', [
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_LIQUIDATION_GUARD_FORCE_EXIT,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'ratio_bot_liquidation_guard_force_exit',
            'bot_type' => 'ratio_bot',
            'bot_instance_id' => $config->id,
            'correlation_id' => $result['decision_record']->correlation_id,
        ]);
        $this->assertDatabaseHas('audit_decision_snapshots', [
            'ratio_bot_decision_id' => $result['decision_record']->id,
            'correlation_id' => $result['decision_record']->correlation_id,
        ]);
    }

    /** @test */
    public function a_short_position_forces_exit_short_not_exit_long(): void
    {
        $config = $this->createFuturesConfig(['mode' => 'restricted_auto']);
        $this->createPosition($config, qty: -2.0, liquidationBufferPct: 1.0, syncedMinutesAgo: 1);
        app(RatioBotStateService::class)->syncPositionSnapshot($config, 'short', 2.0, 50000.0, 0);

        $result = app(RatioBotRealtimeEngine::class)->evaluate($config->fresh('state'), 50000.0);

        $this->assertSame(RatioBotDecision::DECISION_EXIT_SHORT, $result['decision']);
    }

    /** @test */
    public function missing_or_stale_synced_data_blocks_new_entries_but_does_not_force_exit_a_healthy_position(): void
    {
        $config = $this->createFuturesConfig();
        // Position exists and is open, but hasn't been synced in a very long
        // time (reconcile job may be stuck) -- fail toward caution by
        // blocking new entries, but deliberately not by force-exiting a real
        // position purely because monitoring data is old.
        $this->createPosition($config, qty: 1.0, liquidationBufferPct: 40.0, syncedMinutesAgo: 120);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/liquidation-guard");

        $response->assertOk()
            ->assertJsonPath('danger_level', 'critical')
            ->assertJsonPath('action', 'block_new_entries');
    }

    /** @test */
    public function sibling_bots_on_the_same_symbol_and_workspace_are_flagged_as_a_shared_position(): void
    {
        $config = $this->createFuturesConfig(['name' => 'Bot A']);
        $this->createFuturesConfig(['name' => 'Bot B', 'symbol' => $config->symbol]);
        $this->createPosition($config, qty: 1.0, liquidationBufferPct: 40.0, syncedMinutesAgo: 1);

        $response = $this->actingAs($this->user)->getJson("/api/v1/ratio-bots/{$config->id}/liquidation-guard");

        $response->assertOk()->assertJsonPath('shared_position', true);
        $this->assertSame('Bot B', $response->json('sibling_bots.0.name'));
    }

    private function createFuturesConfig(array $overrides = []): RatioBotConfig
    {
        return $this->createConfig(array_merge([
            'market_type' => 'futures',
            'liquidation_guard_enabled' => true,
            'liquidation_watch_buffer_pct' => 15.0,
            'liquidation_critical_buffer_pct' => 5.0,
            'leverage' => 5,
        ], $overrides));
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
            'enabled' => true,
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_FLAT]
        );

        return $config->refresh()->load('state');
    }

    private function createPosition(RatioBotConfig $config, float $qty, float $liquidationBufferPct, int $syncedMinutesAgo): Position
    {
        $symbol = Symbol::firstOrCreate(['symbol' => $config->normalizedSymbol()], [
            'id' => (string) Str::uuid(),
            'type' => 'crypto',
            'exchange' => 'binance_futures',
        ]);

        return Position::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $config->workspace_id,
            'symbol_id' => $symbol->id,
            'market_type' => 'futures',
            'qty' => $qty,
            'quantity' => $qty,
            'avg_price' => 50000.0,
            'avg_entry' => 50000.0,
            'last_mark_price' => 50000.0,
            'liquidation_price' => $qty < 0 ? 55000.0 : 45000.0,
            'liquidation_buffer_pct' => $liquidationBufferPct,
            'mark_price_synced_at' => now()->subMinutes($syncedMinutesAgo),
        ]);
    }

    private function decisionAttrs(RatioBotConfig $config, string $decision, array $metadata = []): array
    {
        return [
            'ratio_bot_config_id' => $config->id,
            'decision' => $decision,
            'evaluated_price' => 50000.0,
            'execution_status' => RatioBotDecision::EXEC_PENDING,
            'metadata' => $metadata,
            'decided_at' => now(),
        ];
    }
}
