<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\RatioBotDecisionService;
use App\Domain\RatioBot\StrategyManagerService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RatioBotDecisionServiceV2Test extends TestCase
{
    use RefreshDatabase;

    private RatioBotDecisionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RatioBotDecisionService();
    }

    // -----------------------------------------------------------------------
    // Inertness pin — the single most important regression guard in this file.
    // -----------------------------------------------------------------------

    /** @test */
    public function evaluate_is_byte_identical_when_v2_disabled(): void
    {
        [$config] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.50, v2Enabled: false);

        $result = $this->service->evaluate($config, 90_000.0);

        $this->assertSame(RatioBotDecision::DECISION_SELL, $result['decision']);
        $this->assertEqualsWithDelta(0.50, $result['ratio'], 0.000001);
        $this->assertArrayNotHasKey('strategy_manager', (array) ($result['decision_record']->metadata['audit'] ?? []));
    }

    // -----------------------------------------------------------------------
    // Staged sell (PARTIAL_SOLD)
    // -----------------------------------------------------------------------

    /** @test */
    public function partial_sold_reevaluates_sell_conditions_against_unchanged_cost_basis(): void
    {
        [$config] = $this->makePartialSoldConfig(costBasis: 100.0, sellThreshold: 0.20);

        // ratio = (125/100)-1 = 0.25 >= 0.20 threshold -> SELL
        $result = $this->service->evaluate($config, 125.0);

        $this->assertSame(RatioBotDecision::DECISION_SELL, $result['decision']);
        $this->assertEqualsWithDelta(100.0, (float) $result['anchor'], 0.0001);
    }

    /** @test */
    public function partial_sold_never_triggers_a_buy(): void
    {
        [$config] = $this->makePartialSoldConfig(costBasis: 100.0, sellThreshold: 0.90);

        // Price collapses far below cost basis — a buy-side reaction must
        // never happen from PARTIAL_SOLD; there is no buy branch at all.
        $result = $this->service->evaluate($config, 10.0);

        $this->assertNotSame(RatioBotDecision::DECISION_BUY, $result['decision']);
    }

    // -----------------------------------------------------------------------
    // Strategy Manager wiring (fail-open)
    // -----------------------------------------------------------------------

    /** @test */
    public function strategy_manager_failure_fails_open_to_static_thresholds(): void
    {
        $this->app->bind(StrategyManagerService::class, function () {
            throw new RuntimeException('simulated strategy manager failure');
        });

        [$config] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.50, v2Enabled: true);

        $result = $this->service->evaluate($config, 90_000.0);

        // Despite StrategyManagerService throwing on every resolution, the
        // static sell_threshold_ratio (0.50) must still be the one applied.
        $this->assertSame(RatioBotDecision::DECISION_SELL, $result['decision']);
        $this->assertEqualsWithDelta(0.50, (float) $result['decision_record']->threshold_used, 0.000001);
    }

    // -----------------------------------------------------------------------
    // Recovery monitoring — entry timing
    // -----------------------------------------------------------------------

    /** @test */
    public function recovery_monitoring_entered_after_timeout_with_no_reserve_remaining(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.90, v2Enabled: true, recoveryEnabled: true, recoveryTimeoutMinutes: 30);
        $state->update(['last_trade_time' => now()->subMinutes(31)]);

        // Price hasn't moved enough to sell (threshold 0.90, tiny bump only).
        $result = $this->service->evaluate($config->fresh('state'), 61_000.0);

        $this->assertSame(RatioBotState::STATE_RECOVERY_MONITORING, $config->fresh('state')->state->position_state);
        $this->assertNotSame(RatioBotDecision::DECISION_SELL, $result['decision']);
    }

    /** @test */
    public function recovery_monitoring_does_not_trigger_before_timeout(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.90, v2Enabled: true, recoveryEnabled: true, recoveryTimeoutMinutes: 30);
        $state->update(['last_trade_time' => now()->subMinutes(5)]);

        $this->service->evaluate($config->fresh('state'), 61_000.0);

        $this->assertSame(RatioBotState::STATE_HOLDING, $config->fresh('state')->state->position_state);
    }

    /** @test */
    public function recovery_monitoring_still_honors_a_real_stop_loss(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.90, v2Enabled: true, recoveryEnabled: true, recoveryTimeoutMinutes: 30);
        $config->update(['stop_loss_pct' => 0.10]);
        $state->update(['last_trade_time' => now()->subMinutes(31)]);

        // First tick: enters recovery monitoring but a stop-loss (-10%) should
        // still fire ahead of any recovery bookkeeping, if triggered.
        $result = $this->service->evaluate($config->fresh('state'), 53_000.0); // -11.6%

        $this->assertSame(RatioBotDecision::DECISION_SELL, $result['decision']);
        $this->assertSame('stop_loss', $result['decision_record']->metadata['trigger']);
    }

    /** @test */
    public function recovery_disabled_bots_never_enter_recovery_states(): void
    {
        [$config, $state] = $this->makeHoldingConfig(buyPrice: 60_000, sellThreshold: 0.90, v2Enabled: true, recoveryEnabled: false);
        $state->update(['last_trade_time' => now()->subYears(1)]);

        $this->service->evaluate($config->fresh('state'), 61_000.0);

        $this->assertSame(RatioBotState::STATE_HOLDING, $config->fresh('state')->state->position_state);
    }

    // -----------------------------------------------------------------------
    // Cash recovery monitoring
    // -----------------------------------------------------------------------

    /** @test */
    public function cash_recovery_monitoring_entered_after_timeout(): void
    {
        [$config, $state] = $this->makeFlatConfig(sellPrice: 90_000, buyThreshold: 0.90, v2Enabled: true, recoveryEnabled: true, recoveryTimeoutMinutes: 30);
        $state->update(['last_trade_time' => now()->subMinutes(31)]);

        $this->service->evaluate($config->fresh('state'), 89_000.0); // barely moved, no buy trigger

        $this->assertSame(RatioBotState::STATE_CASH_RECOVERY_MONITORING, $config->fresh('state')->state->position_state);
    }

    /** @test */
    public function cash_recovery_monitoring_still_honors_a_real_buy_trigger(): void
    {
        [$config, $state] = $this->makeFlatConfig(sellPrice: 90_000, buyThreshold: 0.25, v2Enabled: true, recoveryEnabled: true, recoveryTimeoutMinutes: 30);
        $state->update([
            'position_state' => RatioBotState::STATE_CASH_RECOVERY_MONITORING,
            'recovery_mode' => 'cash_stuck',
            'recovery_started_at' => now()->subMinutes(60),
            'recovery_review_at' => now()->addMinutes(30), // review not due yet
        ]);

        // ratio = (90000/72000)-1 = 0.25 >= 0.25 threshold -> BUY, even
        // though the review window hasn't elapsed.
        $result = $this->service->evaluate($config->fresh('state'), 72_000.0);

        $this->assertSame(RatioBotDecision::DECISION_BUY, $result['decision']);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeHoldingConfig(
        float $buyPrice,
        float $sellThreshold,
        bool $v2Enabled = false,
        bool $recoveryEnabled = false,
        int $recoveryTimeoutMinutes = 60,
    ): array {
        $config = RatioBotConfig::create([
            'workspace_id'        => '00000000-0000-0000-0000-000000000001',
            'symbol'              => 'BTCUSDT',
            'enabled'             => true,
            'sell_threshold_ratio'=> $sellThreshold,
            'buy_threshold_ratio' => 0.25,
            'v2_enabled'          => $v2Enabled,
            'recovery_enabled'    => $recoveryEnabled,
            'recovery_timeout_minutes' => $recoveryTimeoutMinutes,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state'      => RatioBotState::STATE_HOLDING,
            'last_buy_price'      => $buyPrice,
            'last_buy_quantity'   => 1.0,
            'active_anchor_price' => $buyPrice,
            'active_anchor_type'  => 'buy_anchor',
            'last_trade_time'     => now(),
        ]);
        $config->setRelation('state', $state);
        return [$config, $state];
    }

    private function makeFlatConfig(
        float $sellPrice,
        float $buyThreshold,
        bool $v2Enabled = false,
        bool $recoveryEnabled = false,
        int $recoveryTimeoutMinutes = 60,
    ): array {
        $config = RatioBotConfig::create([
            'workspace_id'        => '00000000-0000-0000-0000-000000000001',
            'symbol'              => 'BTCUSDT',
            'enabled'             => true,
            'sell_threshold_ratio'=> 0.50,
            'buy_threshold_ratio' => $buyThreshold,
            'v2_enabled'          => $v2Enabled,
            'recovery_enabled'    => $recoveryEnabled,
            'recovery_timeout_minutes' => $recoveryTimeoutMinutes,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state'      => RatioBotState::STATE_FLAT,
            'last_sell_price'     => $sellPrice,
            'last_sell_quantity'  => 1.0,
            'active_anchor_price' => $sellPrice,
            'active_anchor_type'  => 'sell_anchor',
            'last_trade_time'     => now(),
        ]);
        $config->setRelation('state', $state);
        return [$config, $state];
    }

    private function makePartialSoldConfig(float $costBasis, float $sellThreshold): array
    {
        $config = RatioBotConfig::create([
            'workspace_id'         => '00000000-0000-0000-0000-000000000001',
            'symbol'               => 'BTCUSDT',
            'enabled'              => true,
            'sell_threshold_ratio' => $sellThreshold,
            'buy_threshold_ratio'  => 0.25,
            'v2_enabled'           => true,
            'sell_tranche_count'   => 2,
            'initial_exit_pct'     => 0.5,
        ]);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state'      => RatioBotState::STATE_PARTIAL_SOLD,
            'last_buy_price'      => $costBasis,
            'last_buy_quantity'   => 50.0,
            'active_anchor_price' => $costBasis,
            'active_anchor_type'  => 'buy_anchor',
            'sell_tranches_remaining' => 1,
        ]);
        $config->setRelation('state', $state);
        return [$config, $state];
    }
}
