<?php

namespace Tests\Unit\Pepperstone;

use App\Domain\Ops\OperationalBreakerService;
use App\Domain\Pepperstone\PositionSizingService;
use App\Domain\Pepperstone\RiskEngine;
use App\Models\Instrument;
use App\Models\InstrumentMapping;
use App\Models\Order;
use App\Models\PepperstoneBotConfig;
use App\Models\PepperstoneBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One test per RiskEngine::authorizeTrade() block reason, each asserting
 * the reason triggers in isolation -- every other input is left at a
 * default that would not itself trigger a block (see RiskEngine's own
 * docblock for the exact default context shape).
 */
class RiskEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): RiskEngine
    {
        return new RiskEngine(new OperationalBreakerService(), new PositionSizingService());
    }

    private function instrument(array $overrides = []): Instrument
    {
        return new Instrument(array_merge([
            'canonical_symbol' => 'XAUUSD',
            'market_type' => 'metal',
            'name' => 'Gold',
            'quote_currency' => 'USD',
            'enabled' => true,
        ], $overrides));
    }

    private function mapping(array $overrides = []): InstrumentMapping
    {
        return new InstrumentMapping(array_merge([
            'provider' => 'pepperstone',
            'broker_symbol' => 'XAUUSD',
            'tick_size' => 0.01,
            'tick_value' => 1.0,
            'contract_size' => 100,
            'min_volume' => 0.01,
            'volume_step' => 0.01,
            'margin_requirement' => 0.05,
        ], $overrides));
    }

    private function config(array $overrides = []): PepperstoneBotConfig
    {
        return new PepperstoneBotConfig(array_merge([
            'workspace_id' => (string) Str::uuid(),
            'instrument_id' => (string) Str::uuid(),
            'position_sizing_mode' => 'fixed',
            'base_lot_size' => 0.10,
            'max_grid_levels' => 8,
            'max_total_exposure_lots' => 10,
            'max_position_lots' => 10,
            'spread_limit_pips' => null,
            'daily_loss_limit_pct' => null,
            'basket_stop_loss_pct' => 5,
        ], $overrides));
    }

    private function state(array $overrides = []): PepperstoneBotState
    {
        return new PepperstoneBotState(array_merge([
            'status' => PepperstoneBotState::STATUS_RUNNING,
            'active_levels_count' => 0,
        ], $overrides));
    }

    private function quote(array $overrides = []): array
    {
        return array_merge([
            'mid' => 2000.0,
            'bid' => 1999.9,
            'ask' => 2000.1,
            'spread_pct' => 0.01,
        ], $overrides);
    }

    /** @test */
    public function authorizes_a_perfectly_ordinary_trade(): void
    {
        $result = $this->engine()->authorizeTrade(null, $this->config(), $this->state(), $this->instrument(), $this->mapping(), $this->quote());

        $this->assertTrue($result['authorized']);
        $this->assertNull($result['reason_code']);
    }

    /** @test */
    public function blocks_on_provider_outage_only(): void
    {
        $workspaceId = (string) Str::uuid();
        $breakers = new OperationalBreakerService();
        $breakers->activateProviderOutage($workspaceId, 'pepperstone', 'test outage');

        $engine = new RiskEngine($breakers, new PositionSizingService());
        $result = $engine->authorizeTrade($workspaceId, $this->config(['workspace_id' => $workspaceId]), $this->state(), $this->instrument(), $this->mapping(), $this->quote());

        $this->assertFalse($result['authorized']);
        $this->assertSame('provider_outage', $result['reason_code']);
    }

    /** @test */
    public function blocks_on_instrument_disabled_only(): void
    {
        $result = $this->engine()->authorizeTrade(null, $this->config(), $this->state(), $this->instrument(['enabled' => false]), $this->mapping(), $this->quote());

        $this->assertFalse($result['authorized']);
        $this->assertSame('instrument_disabled', $result['reason_code']);
    }

    /** @test */
    public function blocks_on_stale_feed_when_quote_age_exceeds_threshold(): void
    {
        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['quote_age_seconds' => 999.0, 'max_quote_age_seconds' => 30.0],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('stale_feed', $result['reason_code']);
    }

    /** @test */
    public function blocks_on_stale_feed_when_mid_price_is_non_positive(): void
    {
        $result = $this->engine()->authorizeTrade(null, $this->config(), $this->state(), $this->instrument(), $this->mapping(), $this->quote(['mid' => 0]));

        $this->assertFalse($result['authorized']);
        $this->assertSame('stale_feed', $result['reason_code']);
    }

    /** @test */
    public function blocks_on_spread_too_wide_only(): void
    {
        $config = $this->config(['spread_limit_pips' => 5.0]);
        // bid/ask delta of 1.0 at PIP_SIZE=0.01 => 100 pips, exceeds limit of 5.
        $result = $this->engine()->authorizeTrade(null, $config, $this->state(), $this->instrument(), $this->mapping(), $this->quote(['bid' => 1999.5, 'ask' => 2000.5]));

        $this->assertFalse($result['authorized']);
        $this->assertSame('spread_too_wide', $result['reason_code']);
    }

    /** @test */
    public function does_not_block_on_spread_when_no_limit_is_configured(): void
    {
        $config = $this->config(['spread_limit_pips' => null]);
        $result = $this->engine()->authorizeTrade(null, $config, $this->state(), $this->instrument(), $this->mapping(), $this->quote(['bid' => 1900.0, 'ask' => 2100.0]));

        $this->assertTrue($result['authorized']);
    }

    /** @test */
    public function blocks_on_insufficient_margin_only(): void
    {
        $mapping = $this->mapping(['contract_size' => 100, 'margin_requirement' => 0.05]);
        // 1 lot * 100 * 2000 * 0.05 = $10,000 required; free margin only $1.
        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $mapping,
            $this->quote(),
            ['proposed_lots' => 1.0, 'free_margin' => 1.0],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('insufficient_margin', $result['reason_code']);
    }

    /** @test */
    public function blocks_on_exposure_cap_exceeded_only(): void
    {
        $config = $this->config(['max_total_exposure_lots' => 1.0, 'max_position_lots' => 100]);
        $result = $this->engine()->authorizeTrade(
            null,
            $config,
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['proposed_lots' => 0.5, 'current_exposure_lots' => 0.6],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('exposure_cap_exceeded', $result['reason_code']);
    }

    /** @test */
    public function blocks_on_position_cap_exceeded_only(): void
    {
        $config = $this->config(['max_position_lots' => 1.0, 'max_total_exposure_lots' => 100]);
        $result = $this->engine()->authorizeTrade(
            null,
            $config,
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['proposed_lots' => 0.5, 'current_position_lots' => 0.6, 'current_exposure_lots' => 0],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('position_cap_exceeded', $result['reason_code']);
    }

    /** @test */
    public function blocks_on_level_cap_exceeded_only(): void
    {
        $config = $this->config(['max_grid_levels' => 3]);
        $state = $this->state(['active_levels_count' => 3]);

        $result = $this->engine()->authorizeTrade(null, $config, $state, $this->instrument(), $this->mapping(), $this->quote());

        $this->assertFalse($result['authorized']);
        $this->assertSame('level_cap_exceeded', $result['reason_code']);
    }

    /** @test */
    public function blocks_on_daily_loss_limit_only(): void
    {
        $config = $this->config(['daily_loss_limit_pct' => 5.0]);

        $result = $this->engine()->authorizeTrade(
            null,
            $config,
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['daily_realized_pnl_pct' => -6.0],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('daily_loss_limit', $result['reason_code']);
    }

    /** @test */
    public function does_not_block_on_daily_loss_when_no_limit_is_configured(): void
    {
        $config = $this->config(['daily_loss_limit_pct' => null]);

        $result = $this->engine()->authorizeTrade(
            null,
            $config,
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['daily_realized_pnl_pct' => -50.0],
        );

        $this->assertTrue($result['authorized']);
    }

    /** @test */
    public function blocks_on_duplicate_order_only(): void
    {
        $workspace = Workspace::factory()->create();
        $clientOrderId = 'AUTOFINX-XAUUSD-BUY-' . (string) Str::uuid();

        Order::create([
            'workspace_id' => $workspace->id,
            'side' => 'BUY',
            'quantity' => 0.10,
            'idempotency_key' => $clientOrderId,
        ]);

        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['client_order_id' => $clientOrderId],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('duplicate_order', $result['reason_code']);
    }

    /** @test */
    public function blocks_on_emergency_stop_only(): void
    {
        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['emergency_stop' => true],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('emergency_stop', $result['reason_code']);
    }

    // -----------------------------------------------------------------
    // Basket-model additions (MTF/Basket plan §7) -- each independently
    // triggerable, same discipline as every check above.
    // -----------------------------------------------------------------

    /** @test */
    public function blocks_on_chaotic_regime_only(): void
    {
        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['market_regime' => \App\Domain\Pepperstone\MarketRegimeDetector::REGIME_CHAOTIC],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('regime_blocked', $result['reason_code']);
    }

    /** @test */
    public function blocks_on_low_liquidity_regime_only(): void
    {
        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['market_regime' => \App\Domain\Pepperstone\MarketRegimeDetector::REGIME_LOW_LIQUIDITY],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('regime_blocked', $result['reason_code']);
    }

    /** @test */
    public function does_not_block_on_trending_or_ranging_regimes(): void
    {
        foreach ([
            \App\Domain\Pepperstone\MarketRegimeDetector::REGIME_TRENDING,
            \App\Domain\Pepperstone\MarketRegimeDetector::REGIME_RANGING,
            \App\Domain\Pepperstone\MarketRegimeDetector::REGIME_VOLATILE,
        ] as $regime) {
            $result = $this->engine()->authorizeTrade(
                null, $this->config(), $this->state(), $this->instrument(), $this->mapping(), $this->quote(),
                ['market_regime' => $regime],
            );
            $this->assertTrue($result['authorized'], "Regime {$regime} should not block.");
        }
    }

    /** @test */
    public function blocks_on_direction_conflict_only(): void
    {
        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['direction_conflict' => true],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('direction_conflict', $result['reason_code']);
    }

    /** @test */
    public function blocks_on_portfolio_exposure_cap_exceeded_only(): void
    {
        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['portfolio_exposure_lots' => 0.9, 'portfolio_exposure_cap_lots' => 1.0, 'proposed_lots' => 0.5],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('portfolio_exposure_cap_exceeded', $result['reason_code']);
    }

    /** @test */
    public function does_not_block_on_portfolio_exposure_when_no_cap_is_configured(): void
    {
        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['portfolio_exposure_lots' => 999.0, 'portfolio_exposure_cap_lots' => null],
        );

        $this->assertTrue($result['authorized']);
    }

    /** @test */
    public function blocks_on_margin_reserve_breached_only(): void
    {
        $mapping = $this->mapping(['contract_size' => 100, 'margin_requirement' => 0.05]);
        // Required margin for 1 lot @ 2000 = 100*2000*0.05 = $10,000.
        // free_margin 10,050 leaves only $50 projected reserve -- below a $100 floor.
        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $mapping,
            $this->quote(),
            ['proposed_lots' => 1.0, 'free_margin' => 10050.0, 'margin_safety_reserve_floor' => 100.0],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('margin_reserve_breached', $result['reason_code']);
    }

    /** @test */
    public function does_not_block_on_margin_reserve_when_no_floor_is_configured(): void
    {
        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['margin_safety_reserve_floor' => null],
        );

        $this->assertTrue($result['authorized']);
    }

    /** @test */
    public function blocks_on_cost_not_cleared_only(): void
    {
        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
            ['expected_profit' => 5.0, 'estimated_total_cost' => 10.0],
        );

        $this->assertFalse($result['authorized']);
        $this->assertSame('cost_not_cleared', $result['reason_code']);
    }

    /** @test */
    public function does_not_block_on_cost_clearance_when_not_provided(): void
    {
        $result = $this->engine()->authorizeTrade(
            null,
            $this->config(),
            $this->state(),
            $this->instrument(),
            $this->mapping(),
            $this->quote(),
        );

        $this->assertTrue($result['authorized']);
    }
}
