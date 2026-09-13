<?php

namespace Tests\Feature\RatioBot;

use App\Models\FuturesTradingConfig;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class RatioBotLeverageSanityTest extends TestCase
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
        FuturesTradingConfig::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'max_leverage' => 10,
        ]);
        Queue::fake();
    }

    /** @test */
    public function leverage_above_the_workspace_cap_is_rejected_at_save_time(): void
    {
        $config = $this->createFuturesConfig();

        $response = $this->actingAs($this->user)->putJson("/api/v1/ratio-bots/{$config->id}", [
            'leverage' => 20,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['leverage']);
        $this->assertSame(5.0, (float) $config->fresh()->leverage);
    }

    /** @test */
    public function leverage_at_or_below_the_workspace_cap_is_accepted(): void
    {
        $config = $this->createFuturesConfig();

        $response = $this->actingAs($this->user)->putJson("/api/v1/ratio-bots/{$config->id}", [
            'leverage' => 10,
        ]);

        $response->assertOk();
        $this->assertSame(10.0, (float) $config->fresh()->leverage);
    }

    /** @test */
    public function a_watch_critical_gap_too_narrow_for_the_configured_leverage_is_rejected(): void
    {
        $config = $this->createFuturesConfig(['leverage' => 10]);

        // At 10x leverage the minimum survivable gap is max(1.0, 10*0.3) = 3.0%.
        // A 1% gap (15 -> 14) is far too narrow.
        $response = $this->actingAs($this->user)->putJson("/api/v1/ratio-bots/{$config->id}", [
            'liquidation_watch_buffer_pct' => 15.0,
            'liquidation_critical_buffer_pct' => 14.0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['liquidation_watch_buffer_pct']);
    }

    /** @test */
    public function a_survivable_watch_critical_gap_is_accepted(): void
    {
        $config = $this->createFuturesConfig(['leverage' => 10]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/ratio-bots/{$config->id}", [
            'liquidation_watch_buffer_pct' => 15.0,
            'liquidation_critical_buffer_pct' => 5.0,
        ]);

        $response->assertOk();
    }

    /** @test */
    public function the_gap_check_is_skipped_entirely_when_the_liquidation_guard_is_disabled(): void
    {
        $config = $this->createFuturesConfig(['leverage' => 10, 'liquidation_guard_enabled' => false]);

        $response = $this->actingAs($this->user)->putJson("/api/v1/ratio-bots/{$config->id}", [
            'liquidation_guard_enabled' => false,
            'liquidation_watch_buffer_pct' => 15.0,
            'liquidation_critical_buffer_pct' => 14.0,
        ]);

        $response->assertOk();
    }

    private function createFuturesConfig(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'market_type' => 'futures',
            'sell_threshold_ratio' => 0.50,
            'buy_threshold_ratio' => 0.25,
            'base_quote_amount' => 100.0,
            'execution_mode' => 'sandbox',
            'enabled' => true,
            'leverage' => 5,
            'liquidation_guard_enabled' => true,
            'liquidation_watch_buffer_pct' => 15.0,
            'liquidation_critical_buffer_pct' => 5.0,
        ], $overrides));

        RatioBotState::firstOrCreate(
            ['ratio_bot_config_id' => $config->id],
            ['position_state' => RatioBotState::STATE_FLAT]
        );

        return $config->refresh()->load('state');
    }
}
