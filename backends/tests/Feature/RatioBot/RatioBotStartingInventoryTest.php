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

/**
 * Spec §2 SELL Starting Entry: the bot starts already holding the base
 * asset. Covers the onboarding endpoint that seeds real RatioBotLot rows
 * (80% normal, 20% recovery) from a declared cost basis, so the existing
 * stage-sell / recovery-sell logic manages the position identically to one
 * built up through normal fills.
 */
class RatioBotStartingInventoryTest extends TestCase
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
    public function declaring_starting_inventory_seeds_normal_and_recovery_lots(): void
    {
        $config = $this->createConfig();

        $response = $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/anchor-starting-inventory", [
            'cost_basis_price' => 50000,
            'quantity' => 0.01,
        ]);

        $response->assertOk();

        $lots = RatioBotLot::where('ratio_bot_config_id', $config->id)->get();
        // 3 normal-stage lots (40,20,20 of the 80% pool) + 1 recovery lot (20%).
        $this->assertCount(4, $lots);

        $normalQty = $lots->where('lot_type', RatioBotLot::TYPE_NORMAL)->sum('original_qty');
        $recoveryQty = $lots->where('lot_type', RatioBotLot::TYPE_RECOVERY)->sum('original_qty');
        $this->assertEqualsWithDelta(0.008, $normalQty, 0.000001, '80% of 0.01 should be split across normal lots');
        $this->assertEqualsWithDelta(0.002, $recoveryQty, 0.000001, '20% reserved as the recovery lot');

        foreach ($lots as $lot) {
            $this->assertEqualsWithDelta(50000, (float) $lot->buy_price, 0.01);
            $this->assertSame(RatioBotLot::STATUS_OPEN, $lot->status);
        }

        $state = $config->fresh('state')->state;
        $this->assertSame(RatioBotState::STATE_HOLDING, $state->position_state);
        $this->assertEqualsWithDelta(0.01, (float) $state->last_buy_quantity, 0.000001);
        $this->assertEqualsWithDelta(50000, (float) $state->last_buy_price, 0.01);
    }

    /** @test */
    public function normal_buy_stages_are_treated_as_fully_consumed_after_onboarding(): void
    {
        $config = $this->createConfig();
        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/anchor-starting-inventory", [
            'cost_basis_price' => 50000,
            'quantity' => 0.01,
        ])->assertOk();

        // No further normal BUY should ever fire — price falling hard should
        // just report the normal buy range as exhausted, not start buying.
        $decisions = app(\App\Domain\RatioBot\RatioBotDecisionService::class);
        $result = $decisions->evaluate($config->fresh('state'), 30000);

        $this->assertNotSame(\App\Models\RatioBotDecision::DECISION_BUY, $result['decision']);
    }

    /** @test */
    public function cannot_declare_starting_inventory_when_starting_entry_is_buy(): void
    {
        $config = $this->createConfig(['starting_entry' => 'BUY']);

        $response = $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/anchor-starting-inventory", [
            'cost_basis_price' => 50000,
            'quantity' => 0.01,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function cannot_declare_starting_inventory_twice(): void
    {
        $config = $this->createConfig();
        $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/anchor-starting-inventory", [
            'cost_basis_price' => 50000,
            'quantity' => 0.01,
        ])->assertOk();

        $second = $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/anchor-starting-inventory", [
            'cost_basis_price' => 51000,
            'quantity' => 0.005,
        ]);

        $second->assertStatus(422);
        $this->assertCount(4, RatioBotLot::where('ratio_bot_config_id', $config->id)->get());
    }

    /** @test */
    public function without_v2_enabled_no_lots_are_created_but_state_still_anchors(): void
    {
        $config = $this->createConfig(['v2_enabled' => false]);

        $response = $this->actingAs($this->user)->postJson("/api/v1/ratio-bots/{$config->id}/anchor-starting-inventory", [
            'cost_basis_price' => 50000,
            'quantity' => 0.01,
        ]);

        $response->assertOk();
        $this->assertCount(0, RatioBotLot::where('ratio_bot_config_id', $config->id)->get());
        $this->assertSame(RatioBotState::STATE_HOLDING, $config->fresh('state')->state->position_state);
    }

    private function createConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'starting_entry' => 'SELL',
            'v2_enabled' => true,
            'algorithm' => 'ratio_threshold',
            'market_type' => 'spot',
            'base_quote_amount' => 100,
            'stage_buy_allocations' => [40, 20, 20],
            'stage_buy_drops' => [10, 5, 5],
            'stage_sell_allocations' => [20, 20, 40],
            'stage_sell_rises' => [5, 5, 10],
            'strategy_reserve_pct' => 20,
            'recovery_enabled' => true,
            'recovery_timeout_minutes' => 120,
            'execution_mode' => 'sandbox',
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_IDLE]
        );

        return $config->refresh()->load('state');
    }
}
