<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\DynamicObservationWindow;
use App\Models\RatioBotConfig;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicObservationWindowTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
    }

    public function test_a_strengthening_trend_extends_the_window_within_the_max_clamp(): void
    {
        $config = $this->makeBot(['recovery_timeout_minutes' => 60, 'recovery_max_observation_minutes' => 240]);
        $state = $this->stateWithPreviousComposite($config, previous: 0.10);

        $result = app(DynamicObservationWindow::class)->resolveWindowMinutes($config, $state, ['composite_score' => 0.40]);

        $this->assertSame(90, $result['minutes']); // 60 * 1.5
        $this->assertEqualsWithDelta(1.5, $result['adjustment'], 0.0001);
    }

    public function test_a_deteriorating_trend_shortens_the_window_within_the_min_clamp(): void
    {
        $config = $this->makeBot(['recovery_timeout_minutes' => 60, 'recovery_min_observation_minutes' => 15]);
        $state = $this->stateWithPreviousComposite($config, previous: 0.40);

        $result = app(DynamicObservationWindow::class)->resolveWindowMinutes($config, $state, ['composite_score' => 0.10]);

        $this->assertSame(30, $result['minutes']); // 60 * 0.5
        $this->assertEqualsWithDelta(0.5, $result['adjustment'], 0.0001);
    }

    public function test_a_runaway_strengthening_scenario_never_grows_past_the_max_clamp(): void
    {
        $config = $this->makeBot(['recovery_timeout_minutes' => 200, 'recovery_max_observation_minutes' => 240]);
        $state = $this->stateWithPreviousComposite($config, previous: -0.50);

        // 200 * 1.5 = 300, well past the 240 ceiling.
        $result = app(DynamicObservationWindow::class)->resolveWindowMinutes($config, $state, ['composite_score' => 0.90]);

        $this->assertSame(240, $result['minutes']);
    }

    public function test_a_runaway_weakening_scenario_never_shrinks_past_the_min_clamp(): void
    {
        $config = $this->makeBot(['recovery_timeout_minutes' => 20, 'recovery_min_observation_minutes' => 15]);
        $state = $this->stateWithPreviousComposite($config, previous: 0.90);

        // 20 * 0.5 = 10, below the 15-minute floor.
        $result = app(DynamicObservationWindow::class)->resolveWindowMinutes($config, $state, ['composite_score' => -0.90]);

        $this->assertSame(15, $result['minutes']);
    }

    public function test_no_prior_composite_yields_a_neutral_1x_adjustment(): void
    {
        $config = $this->makeBot(['recovery_timeout_minutes' => 60]);
        $state = RatioBotState::create(['ratio_bot_config_id' => $config->id, 'position_state' => RatioBotState::STATE_RECOVERY_MONITORING]);

        $result = app(DynamicObservationWindow::class)->resolveWindowMinutes($config, $state, ['composite_score' => 0.90]);

        $this->assertSame(60, $result['minutes']);
        $this->assertEqualsWithDelta(1.0, $result['adjustment'], 0.0001);
    }

    // -- WalletAwareDirectionFallback override params --------------------
    //
    // These confirm the extended signature (baseMinutesOverride,
    // previousCompositeOverride, minMinutesOverride, maxMinutesOverride)
    // sources every value from the ARGUMENTS, never from
    // $config->recovery_timeout_minutes / recovery_min/max_observation_minutes
    // or $state->recovery_trend_composite_at_last_review -- proving the two
    // features (Recovery-monitoring vs WalletAwareDirectionFallback) cannot
    // clobber each other's velocity tracking on the same bot/state row.

    public function test_override_params_source_base_and_clamp_from_arguments_not_from_recovery_config(): void
    {
        // Recovery-owned config fields deliberately set to values that would
        // produce a DIFFERENT result than the overrides below, so a bug that
        // silently falls back to reading them would be caught.
        $config = $this->makeBot([
            'recovery_timeout_minutes' => 999,
            'recovery_min_observation_minutes' => 1,
            'recovery_max_observation_minutes' => 5000,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_IDLE,
            // Recovery-owned field set to a value that would produce a
            // different velocity/adjustment than the override below.
            'recovery_trend_composite_at_last_review' => 0.99,
        ]);

        $result = app(DynamicObservationWindow::class)->resolveWindowMinutes(
            $config,
            $state,
            ['composite_score' => 0.40],
            baseMinutesOverride: 60,
            previousCompositeOverride: 0.10,
            minMinutesOverride: 15,
            maxMinutesOverride: 240,
        );

        // velocity = 0.40 - 0.10 = 0.30 > STRONG_VELOCITY -> 1.5x -> 60*1.5=90
        $this->assertSame(90, $result['minutes']);
        $this->assertSame(60, $result['base_minutes']);
        $this->assertEqualsWithDelta(0.10, $result['previous_composite'], 0.0001);
        $this->assertEqualsWithDelta(1.5, $result['adjustment'], 0.0001);
    }

    public function test_override_params_respect_a_null_previous_composite_override_as_no_prior_review(): void
    {
        $config = $this->makeBot(['recovery_timeout_minutes' => 60]);
        // Recovery-owned field HAS a value, but must be ignored entirely when
        // override params are supplied -- a null previousCompositeOverride
        // means "no prior wallet-fallback review yet", not "fall back to the
        // recovery field".
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_IDLE,
            'recovery_trend_composite_at_last_review' => 0.90,
        ]);

        $result = app(DynamicObservationWindow::class)->resolveWindowMinutes(
            $config,
            $state,
            ['composite_score' => 0.40],
            baseMinutesOverride: 60,
            previousCompositeOverride: null,
            minMinutesOverride: 15,
            maxMinutesOverride: 240,
        );

        $this->assertNull($result['previous_composite']);
        $this->assertEqualsWithDelta(1.0, $result['adjustment'], 0.0001);
        $this->assertSame(60, $result['minutes']);
    }

    public function test_override_params_clamp_uses_the_override_min_max_not_the_recovery_config_clamp(): void
    {
        $config = $this->makeBot([
            'recovery_min_observation_minutes' => 1,
            'recovery_max_observation_minutes' => 5000,
        ]);
        $state = RatioBotState::create(['ratio_bot_config_id' => $config->id, 'position_state' => RatioBotState::STATE_IDLE]);

        // base=200, adjustment=1.5 (strong velocity) -> 300, but the override
        // max clamp of 100 must win over the recovery config's 5000 max.
        $result = app(DynamicObservationWindow::class)->resolveWindowMinutes(
            $config,
            $state,
            ['composite_score' => 0.90],
            baseMinutesOverride: 200,
            previousCompositeOverride: -0.50,
            minMinutesOverride: 10,
            maxMinutesOverride: 100,
        );

        $this->assertSame(100, $result['minutes']);
        $this->assertSame(10, $result['min_minutes']);
        $this->assertSame(100, $result['max_minutes']);
    }

    private function makeBot(array $overrides = []): RatioBotConfig
    {
        return RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'execution_mode' => 'sandbox',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'enabled' => true,
        ], $overrides));
    }

    private function stateWithPreviousComposite(RatioBotConfig $config, float $previous): RatioBotState
    {
        return RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_RECOVERY_MONITORING,
            'recovery_trend_composite_at_last_review' => $previous,
            'recovery_trend_reviewed_at' => now()->subMinutes(10),
        ]);
    }
}
