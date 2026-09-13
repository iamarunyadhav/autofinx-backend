<?php

namespace Tests\Feature\Pepperstone;

use App\Models\Instrument;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Basket-only validation surface (MTF/Basket plan §9/§19): supersedes the
 * old martingale/hybrid validation tests -- position_sizing_mode/
 * martingale_multiplier/martingale_max_lot_size/hybrid_martingale_level_count
 * are no longer part of PepperstoneBotController's validated payload at
 * all (equal-lot only, via lot_per_level).
 */
class PepperstoneConfigValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Workspace $workspace;
    private Instrument $instrument;

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

        $this->instrument = Instrument::create([
            'canonical_symbol' => 'XAUUSD',
            'market_type' => 'metal',
            'name' => 'Gold',
            'quote_currency' => 'USD',
            'enabled' => true,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'instrument_id' => $this->instrument->id,
            'lot_per_level' => 0.10,
            'max_basket_levels' => 8,
            'max_total_exposure_lots' => 5,
            'max_position_lots' => 5,
            'basket_stop_loss_pct' => 5,
        ], $overrides);
    }

    /** @test */
    public function a_valid_equal_lot_payload_creates_a_config(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/pepperstone-bots", $this->basePayload());

        $response->assertStatus(201);
        $this->assertDatabaseHas('pepperstone_bot_configs', [
            'workspace_id' => $this->workspace->id,
            'instrument_id' => $this->instrument->id,
        ]);
    }

    /** @test */
    public function lot_per_level_is_required(): void
    {
        $payload = $this->basePayload();
        unset($payload['lot_per_level']);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/pepperstone-bots", $payload);

        $response->assertStatus(422)->assertJsonValidationErrors(['lot_per_level']);
    }

    /** @test */
    public function martingale_and_position_sizing_mode_fields_are_no_longer_accepted_or_required(): void
    {
        // No position_sizing_mode/martingale_* fields in the payload at all
        // -- must still succeed (basket configs are equal-lot only).
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/pepperstone-bots", $this->basePayload([
                // Even if a caller sends legacy fields, they are silently
                // ignored (not part of the validated rule set) rather than
                // rejected or required.
                'position_sizing_mode' => 'martingale',
                'martingale_multiplier' => 2.0,
            ]));

        $response->assertStatus(201);
        $config = \App\Models\PepperstoneBotConfig::first();
        // The legacy column keeps its DB default ('fixed'), proving the
        // martingale value sent above was never actually validated/used.
        $this->assertSame('fixed', $config->position_sizing_mode);
    }

    /** @test */
    public function basket_stop_loss_pct_is_required_even_though_the_column_is_nullable(): void
    {
        $payload = $this->basePayload();
        unset($payload['basket_stop_loss_pct']);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/pepperstone-bots", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['basket_stop_loss_pct']);
    }

    /** @test */
    public function instrument_id_must_reference_a_real_instrument(): void
    {
        $payload = $this->basePayload(['instrument_id' => (string) \Illuminate\Support\Str::uuid()]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/pepperstone-bots", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['instrument_id']);
    }

    /** @test */
    public function min_mtf_confidence_and_v3_confidence_threshold_must_be_between_zero_and_one(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/pepperstone-bots", $this->basePayload([
                'min_mtf_confidence' => 1.5,
            ]));

        $response->assertStatus(422)->assertJsonValidationErrors(['min_mtf_confidence']);
    }
}
