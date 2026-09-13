<?php

namespace Tests\Unit\ForexGrid;

use App\Domain\ForexGrid\GridLotSizer;
use App\Models\ForexGridBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GridLotSizerTest extends TestCase
{
    use RefreshDatabase;

    private const DELTA = 0.0000001;

    private function makeConfig(array $overrides = []): ForexGridBotConfig
    {
        return ForexGridBotConfig::create(array_merge([
            'workspace_id' => (string) Str::uuid(),
            'symbol' => 'EURUSD',
            'grid_spacing_pips' => 20,
            'position_sizing_mode' => 'fixed',
            'base_lot_size' => 0.10,
            'max_grid_levels' => 8,
            'max_total_exposure_lots' => 10,
            'basket_stop_loss_pct' => 5,
        ], $overrides));
    }

    /** @test */
    public function fixed_mode_always_returns_base_lot_size(): void
    {
        $config = $this->makeConfig(['position_sizing_mode' => 'fixed', 'base_lot_size' => 0.15]);
        $sizer = new GridLotSizer();

        foreach ([0, 1, 5, 10] as $level) {
            $this->assertEqualsWithDelta(0.15, $sizer->lotSizeForLevel($level, $config), self::DELTA);
        }
    }

    /** @test */
    public function martingale_mode_escalates_geometrically(): void
    {
        $config = $this->makeConfig([
            'position_sizing_mode' => 'martingale',
            'base_lot_size' => 0.10,
            'martingale_multiplier' => 2.0,
            'martingale_max_lot_size' => 100,
        ]);
        $sizer = new GridLotSizer();

        $this->assertEqualsWithDelta(0.10, $sizer->lotSizeForLevel(0, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.20, $sizer->lotSizeForLevel(1, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.40, $sizer->lotSizeForLevel(2, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.80, $sizer->lotSizeForLevel(3, $config), self::DELTA);
    }

    /** @test */
    public function martingale_mode_clips_at_max_lot_size(): void
    {
        $config = $this->makeConfig([
            'position_sizing_mode' => 'martingale',
            'base_lot_size' => 0.10,
            'martingale_multiplier' => 2.0,
            'martingale_max_lot_size' => 0.30,
        ]);
        $sizer = new GridLotSizer();

        $this->assertEqualsWithDelta(0.10, $sizer->lotSizeForLevel(0, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.20, $sizer->lotSizeForLevel(1, $config), self::DELTA);
        // 0.10 * 2^2 = 0.40, clipped to 0.30
        $this->assertEqualsWithDelta(0.30, $sizer->lotSizeForLevel(2, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.30, $sizer->lotSizeForLevel(5, $config), self::DELTA);
    }

    /** @test */
    public function hybrid_mode_escalates_below_the_boundary_and_flattens_at_it(): void
    {
        $config = $this->makeConfig([
            'position_sizing_mode' => 'hybrid',
            'base_lot_size' => 0.10,
            'martingale_multiplier' => 2.0,
            'martingale_max_lot_size' => 100,
            'hybrid_martingale_level_count' => 3,
        ]);
        $sizer = new GridLotSizer();

        // Levels 0, 1, 2 (< 3) escalate geometrically.
        $this->assertEqualsWithDelta(0.10, $sizer->lotSizeForLevel(0, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.20, $sizer->lotSizeForLevel(1, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.40, $sizer->lotSizeForLevel(2, $config), self::DELTA);

        // Level 3 (the exact boundary, hybrid_martingale_level_count) and
        // beyond flatten to the capped final-escalation size:
        // base * multiplier^(escalationLevels - 1) = 0.10 * 2^2 = 0.40.
        $this->assertEqualsWithDelta(0.40, $sizer->lotSizeForLevel(3, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.40, $sizer->lotSizeForLevel(4, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.40, $sizer->lotSizeForLevel(10, $config), self::DELTA);
    }

    /** @test */
    public function hybrid_mode_final_escalation_size_is_itself_clipped_by_max_lot_size(): void
    {
        $config = $this->makeConfig([
            'position_sizing_mode' => 'hybrid',
            'base_lot_size' => 0.10,
            'martingale_multiplier' => 3.0,
            'martingale_max_lot_size' => 0.50,
            'hybrid_martingale_level_count' => 3,
        ]);
        $sizer = new GridLotSizer();

        // Level 0: 0.10, Level 1: 0.30, Level 2: 0.90 clipped to 0.50
        $this->assertEqualsWithDelta(0.10, $sizer->lotSizeForLevel(0, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.30, $sizer->lotSizeForLevel(1, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.50, $sizer->lotSizeForLevel(2, $config), self::DELTA);

        // Post-boundary levels flatten to the same clipped value.
        $this->assertEqualsWithDelta(0.50, $sizer->lotSizeForLevel(3, $config), self::DELTA);
        $this->assertEqualsWithDelta(0.50, $sizer->lotSizeForLevel(9, $config), self::DELTA);
    }
}
