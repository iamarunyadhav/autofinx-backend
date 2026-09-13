<?php

namespace Tests\Unit\Pepperstone;

use App\Domain\Pepperstone\PositionSizingService;
use App\Models\InstrumentMapping;
use App\Models\PepperstoneBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PositionSizingServiceTest extends TestCase
{
    use RefreshDatabase;

    private const DELTA = 0.0000001;

    private function makeConfig(array $overrides = []): PepperstoneBotConfig
    {
        return new PepperstoneBotConfig(array_merge([
            'workspace_id' => (string) Str::uuid(),
            'instrument_id' => (string) Str::uuid(),
            'position_sizing_mode' => 'fixed',
            'base_lot_size' => 0.10,
            'max_grid_levels' => 8,
            'max_total_exposure_lots' => 10,
            'max_position_lots' => 10,
            'basket_stop_loss_pct' => 5,
        ], $overrides));
    }

    private function makeMapping(array $overrides = []): InstrumentMapping
    {
        return new InstrumentMapping(array_merge([
            'instrument_id' => (string) Str::uuid(),
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

    /** @test */
    public function reproduces_grid_lot_sizers_fixed_mode_math_exactly(): void
    {
        $config = $this->makeConfig(['position_sizing_mode' => 'fixed', 'base_lot_size' => 0.15]);
        $sizer = new PositionSizingService();

        foreach ([0, 1, 5, 10] as $level) {
            $this->assertEqualsWithDelta(0.15, $sizer->lotSizeForLevel($level, $config), self::DELTA);
        }
    }

    /** @test */
    public function reproduces_grid_lot_sizers_martingale_mode_math_exactly(): void
    {
        $config = $this->makeConfig([
            'position_sizing_mode' => 'martingale',
            'base_lot_size' => 0.10,
            'martingale_multiplier' => 2.0,
            'martingale_max_lot_size' => 100,
        ]);
        $sizer = new PositionSizingService();

        $this->assertEqualsWithDelta(0.10, $sizer->lotSizeForLevel(0, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.20, $sizer->lotSizeForLevel(1, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.40, $sizer->lotSizeForLevel(2, $config), self::DELTA);
    }

    /** @test */
    public function reproduces_grid_lot_sizers_hybrid_mode_math_exactly(): void
    {
        $config = $this->makeConfig([
            'position_sizing_mode' => 'hybrid',
            'base_lot_size' => 0.10,
            'martingale_multiplier' => 2.0,
            'martingale_max_lot_size' => 100,
            'hybrid_martingale_level_count' => 3,
        ]);
        $sizer = new PositionSizingService();

        $this->assertEqualsWithDelta(0.10, $sizer->lotSizeForLevel(0, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.20, $sizer->lotSizeForLevel(1, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.40, $sizer->lotSizeForLevel(2, $config), self::DELTA);
        // Boundary and beyond flatten to the capped final-escalation size.
        $this->assertEqualsWithDelta(0.40, $sizer->lotSizeForLevel(3, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.40, $sizer->lotSizeForLevel(10, $config), self::DELTA);
    }

    /** @test */
    public function rounds_down_to_the_nearest_volume_step(): void
    {
        $config = $this->makeConfig(['position_sizing_mode' => 'fixed', 'base_lot_size' => 0.017]);
        $mapping = $this->makeMapping(['volume_step' => 0.01, 'min_volume' => 0.01]);
        $sizer = new PositionSizingService();

        $result = $sizer->sizeForLevel(0, $config, $mapping);

        $this->assertFalse($result['rejected']);
        $this->assertEqualsWithDelta(0.01, $result['lots'], self::DELTA);
    }

    /** @test */
    public function rejects_when_rounded_size_is_below_min_volume_instead_of_rounding_up(): void
    {
        $config = $this->makeConfig(['position_sizing_mode' => 'fixed', 'base_lot_size' => 0.004]);
        $mapping = $this->makeMapping(['volume_step' => 0.01, 'min_volume' => 0.01]);
        $sizer = new PositionSizingService();

        $result = $sizer->sizeForLevel(0, $config, $mapping);

        $this->assertTrue($result['rejected']);
        $this->assertSame('below_min_volume', $result['reason']);
        $this->assertNull($result['lots']);
    }

    /** @test */
    public function exact_multiple_of_volume_step_is_not_rounded_down_further(): void
    {
        $config = $this->makeConfig(['position_sizing_mode' => 'fixed', 'base_lot_size' => 0.05]);
        $mapping = $this->makeMapping(['volume_step' => 0.01, 'min_volume' => 0.01]);
        $sizer = new PositionSizingService();

        $result = $sizer->sizeForLevel(0, $config, $mapping);

        $this->assertFalse($result['rejected']);
        $this->assertEqualsWithDelta(0.05, $result['lots'], self::DELTA);
    }

    /** @test */
    public function martingale_escalation_zone_edge_is_rounded_and_clipped_together(): void
    {
        $config = $this->makeConfig([
            'position_sizing_mode' => 'martingale',
            'base_lot_size' => 0.013,
            'martingale_multiplier' => 2.0,
            'martingale_max_lot_size' => 0.10,
        ]);
        $mapping = $this->makeMapping(['volume_step' => 0.01, 'min_volume' => 0.01]);
        $sizer = new PositionSizingService();

        // Level 0: 0.013 rounds down to 0.01.
        $level0 = $sizer->sizeForLevel(0, $config, $mapping);
        $this->assertFalse($level0['rejected']);
        $this->assertEqualsWithDelta(0.01, $level0['lots'], self::DELTA);

        // Level 3: raw = 0.013 * 2^3 = 0.104, clipped to 0.10 (max), then
        // rounded to the nearest 0.01 step -- already exact.
        $level3 = $sizer->sizeForLevel(3, $config, $mapping);
        $this->assertFalse($level3['rejected']);
        $this->assertEqualsWithDelta(0.10, $level3['lots'], self::DELTA);
    }

    /** @test */
    public function insufficient_margin_is_detected_against_free_margin(): void
    {
        $mapping = $this->makeMapping(['contract_size' => 100, 'margin_requirement' => 0.05]);
        $sizer = new PositionSizingService();

        // 1 lot * 100 contract_size * 2000 price * 0.05 margin = $10,000 required.
        $this->assertTrue($sizer->hasSufficientMargin(1.0, 2000.0, $mapping, 10000.0));
        $this->assertFalse($sizer->hasSufficientMargin(1.0, 2000.0, $mapping, 9999.99));
    }

    /** @test */
    public function margin_requirement_defaults_to_full_notional_when_unconfigured(): void
    {
        $mapping = $this->makeMapping(['contract_size' => 100, 'margin_requirement' => null]);
        $sizer = new PositionSizingService();

        // No leverage confirmed yet -> conservative default of 100% notional.
        $this->assertEqualsWithDelta(200000.0, $sizer->requiredMargin(1.0, 2000.0, $mapping), self::DELTA);
    }

    // -----------------------------------------------------------------
    // sizeEqualLot() -- basket configs' ONLY sizing path (MTF/Basket plan
    // §9). Ignores position_sizing_mode/martingale_* entirely; always
    // sizes to lot_per_level, reusing the same rounding/rejection rules.
    // -----------------------------------------------------------------

    /** @test */
    public function equal_lot_sizing_ignores_position_sizing_mode_and_always_uses_lot_per_level(): void
    {
        $config = $this->makeConfig(['position_sizing_mode' => 'martingale', 'lot_per_level' => 0.20]);
        $mapping = $this->makeMapping(['volume_step' => 0.01, 'min_volume' => 0.01]);
        $sizer = new PositionSizingService();

        $result = $sizer->sizeEqualLot($config, $mapping);

        $this->assertFalse($result['rejected']);
        $this->assertEqualsWithDelta(0.20, $result['lots'], self::DELTA);
    }

    /** @test */
    public function equal_lot_sizing_rounds_down_to_the_nearest_volume_step_same_as_sizeForLevel(): void
    {
        $config = $this->makeConfig(['lot_per_level' => 0.017]);
        $mapping = $this->makeMapping(['volume_step' => 0.01, 'min_volume' => 0.01]);
        $sizer = new PositionSizingService();

        $result = $sizer->sizeEqualLot($config, $mapping);

        $this->assertFalse($result['rejected']);
        $this->assertEqualsWithDelta(0.01, $result['lots'], self::DELTA);
    }

    /** @test */
    public function equal_lot_sizing_rejects_below_min_volume_instead_of_rounding_up(): void
    {
        $config = $this->makeConfig(['lot_per_level' => 0.004]);
        $mapping = $this->makeMapping(['volume_step' => 0.01, 'min_volume' => 0.01]);
        $sizer = new PositionSizingService();

        $result = $sizer->sizeEqualLot($config, $mapping);

        $this->assertTrue($result['rejected']);
        $this->assertSame('below_min_volume', $result['reason']);
    }
}
