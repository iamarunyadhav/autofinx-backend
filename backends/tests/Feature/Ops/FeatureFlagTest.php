<?php

namespace Tests\Feature\Ops;

use App\Domain\Ops\FeatureFlagService;
use App\Models\User;
use Tests\TestCase;

class FeatureFlagTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /**
     * Behavioral-parity: for every known unified_forecast/scheduler flag,
     * a fresh install (no feature_flags row yet) must resolve to EXACTLY
     * the same boolean the old raw config() call returned before this
     * task's changes -- this round changes zero live behavior.
     */
    public function test_default_values_match_real_config_defaults_with_no_row_present(): void
    {
        $service = app(FeatureFlagService::class);

        $this->assertSame((bool) config('unified_forecast.quick_trade_shadow_enabled', true),
            $service->enabled('unified_forecast.quick_trade_shadow', true));
        $this->assertSame((bool) config('unified_forecast.robot_trade_shadow_enabled', true),
            $service->enabled('unified_forecast.robot_trade_shadow', true));

        $this->assertSame((bool) config('autofinx.scheduler.ratio_bots_backtest', true),
            $service->enabled('scheduler.ratio_bot_forecast_backtest', true));
        $this->assertSame((bool) config('autofinx.scheduler.quick_trade_forecast_backtest', true),
            $service->enabled('scheduler.quick_trade_forecast_backtest', true));
        $this->assertSame((bool) config('autofinx.scheduler.robot_trade_forecast_backtest', true),
            $service->enabled('scheduler.robot_trade_forecast_backtest', true));
        $this->assertSame((bool) config('autofinx.scheduler.future_trade_forecast_backtest', true),
            $service->enabled('scheduler.future_trade_forecast_backtest', true));
        $this->assertSame((bool) config('autofinx.scheduler.fast2x_calibration_backtest', true),
            $service->enabled('scheduler.fast2x_calibration_backtest', true));
        $this->assertSame((bool) config('autofinx.scheduler.forecast_readiness_evaluation', true),
            $service->enabled('scheduler.forecast_readiness_evaluation', true));

        // Reserved/not-yet-wired flags default OFF.
        $this->assertFalse($service->enabled('unified_forecast.quick_trade_live', false));
        $this->assertFalse($service->enabled('unified_forecast.robot_trade_live', false));
    }

    public function test_sync_command_seeds_every_registry_key(): void
    {
        $this->artisan('feature-flags:sync')->assertSuccessful();

        foreach (array_keys(FeatureFlagService::REGISTRY) as $key) {
            $this->assertDatabaseHas('feature_flags', ['key' => $key]);
        }
    }

    public function test_index_route_lists_flags_grouped_by_category(): void
    {
        $this->artisan('feature-flags:sync');

        $response = $this->getJson('/api/v1/feature-flags')->assertOk();
        $response->assertJsonStructure(['flags', 'grouped']);
        $response->assertJsonFragment(['key' => 'unified_forecast.quick_trade_shadow']);
    }

    /** The core proof this task asked for: toggling via the API actually changes what FeatureFlagService::enabled() returns, not just the DB row. */
    public function test_patch_toggle_actually_changes_service_resolution(): void
    {
        $key = 'scheduler.forecast_readiness_evaluation';
        $service = app(FeatureFlagService::class);

        $this->assertTrue($service->enabled($key, true));

        $this->patchJson("/api/v1/feature-flags/{$key}", ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('enabled', false);

        $this->assertFalse($service->enabled($key, true));
        $this->assertDatabaseHas('feature_flags', [
            'key' => $key,
            'enabled' => false,
            'updated_by' => $this->user->id,
        ]);

        // Flip back on and confirm the resolution flips back too.
        $this->patchJson("/api/v1/feature-flags/{$key}", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('enabled', true);

        $this->assertTrue($service->enabled($key, true));
    }

    public function test_patch_rejects_unknown_key(): void
    {
        $this->patchJson('/api/v1/feature-flags/not.a.real.flag', ['enabled' => true])
            ->assertNotFound();
    }

    public function test_scheduler_flag_reflects_immediately_after_toggle(): void
    {
        // routes/console.php's `fast2x:calibration-backtest` schedule entry
        // is gated by this exact key -- confirms the toggle this task wires
        // in actually flips the same value that gate reads.
        app(FeatureFlagService::class)->set('scheduler.fast2x_calibration_backtest', false);
        $this->assertFalse(app(FeatureFlagService::class)->enabled('scheduler.fast2x_calibration_backtest', true));

        app(FeatureFlagService::class)->set('scheduler.fast2x_calibration_backtest', true);
        $this->assertTrue(app(FeatureFlagService::class)->enabled('scheduler.fast2x_calibration_backtest', true));
    }
}
