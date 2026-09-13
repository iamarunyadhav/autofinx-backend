<?php

namespace Tests\Feature\ForexGrid;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForexGridConfigValidationTest extends TestCase
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

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'symbol' => 'EURUSD',
            'grid_spacing_pips' => 20,
            'position_sizing_mode' => 'fixed',
            'base_lot_size' => 0.10,
            'max_grid_levels' => 8,
            'max_total_exposure_lots' => 5,
            'basket_stop_loss_pct' => 5,
        ], $overrides);
    }

    /** @test */
    public function fixed_mode_does_not_require_martingale_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/forex-grid-bots", $this->basePayload());

        $response->assertStatus(201);
    }

    /** @test */
    public function martingale_mode_requires_multiplier_and_max_lot_size(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/forex-grid-bots", $this->basePayload([
                'position_sizing_mode' => 'martingale',
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['martingale_multiplier']);
    }

    /** @test */
    public function martingale_mode_with_both_fields_succeeds(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/forex-grid-bots", $this->basePayload([
                'position_sizing_mode' => 'martingale',
                'martingale_multiplier' => 2.0,
                'martingale_max_lot_size' => 1.0,
            ]));

        $response->assertStatus(201);
    }

    /** @test */
    public function hybrid_mode_requires_level_count(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/forex-grid-bots", $this->basePayload([
                'position_sizing_mode' => 'hybrid',
                'martingale_multiplier' => 2.0,
                'martingale_max_lot_size' => 1.0,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hybrid_martingale_level_count']);
    }

    /** @test */
    public function hybrid_mode_level_count_cannot_exceed_max_grid_levels(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/forex-grid-bots", $this->basePayload([
                'position_sizing_mode' => 'hybrid',
                'martingale_multiplier' => 2.0,
                'martingale_max_lot_size' => 1.0,
                'hybrid_martingale_level_count' => 20,
                'max_grid_levels' => 8,
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hybrid_martingale_level_count']);
    }

    /** @test */
    public function hybrid_mode_level_count_within_max_grid_levels_succeeds(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/forex-grid-bots", $this->basePayload([
                'position_sizing_mode' => 'hybrid',
                'martingale_multiplier' => 2.0,
                'martingale_max_lot_size' => 1.0,
                'hybrid_martingale_level_count' => 3,
                'max_grid_levels' => 8,
            ]));

        $response->assertStatus(201);
    }

    /** @test */
    public function basket_stop_loss_pct_is_required_even_though_the_column_is_nullable(): void
    {
        $payload = $this->basePayload();
        unset($payload['basket_stop_loss_pct']);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/forex-grid-bots", $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['basket_stop_loss_pct']);
    }
}
