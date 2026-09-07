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
