<?php

namespace Tests\Unit\Trading;

use App\Domain\Trading\QuickTradeCostModel;
use Tests\TestCase;

/**
 * Part C2 -- the max-achievable-move helper must reproduce the spec's §0.1
 * table EXACTLY, because every "this duration is impossible at current
 * volatility" message the UI shows is derived from it.
 *
 * Also covers C4 (which constraint is actually binding) and C5 (maker_entry
 * cost math + its live-mode refusal).
 */
class QuickTradeCostModelTest extends TestCase
{
    private QuickTradeCostModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new QuickTradeCostModel();
        // Pin to the verified production values (see the class docblock):
        // there is no config/trading.php or config/robot_trade.php in this
        // repo, so these inline defaults ARE production. Setting them
        // explicitly keeps this test honest if that ever changes.
        config()->set('trading.binance_futures_taker_fee_rate', 0.0005);
        config()->set('robot_trade.live_slippage_buffer_pct', 0.04);
        config()->set('robot_trade.live_min_edge_cost_multiplier', 2.0);
        config()->set('robot_trade.live_min_expected_net_usdt', 0.03);
        config()->set('quick_trade.maker_fee_rate', 0.0002);
        config()->set('quick_trade.fee_model', 'taker');
    }

    public function test_taker_cost_model_matches_the_spec_numbers(): void
    {
        $costs = $this->model->costs('taker');

        // 0.05% * 2 legs + 0.04% slippage = 0.14%
        $this->assertEqualsWithDelta(0.14, $costs['cost_pct'], 1e-9);
        // 0.14% * 2.0 safety multiplier = 0.28%
        $this->assertEqualsWithDelta(0.28, $costs['required_move_pct'], 1e-9);
        $this->assertSame('taker', $costs['fee_model']);
        $this->assertSame('config', $costs['fee_rate_source']);
    }

    public function test_maker_entry_cost_model_matches_the_spec_numbers(): void
    {
        $costs = $this->model->costs('maker_entry');

        // 0.02% maker entry + 0.05% taker exit + 0.04% slippage = 0.11%
        $this->assertEqualsWithDelta(0.11, $costs['cost_pct'], 1e-9);
        // -> 0.22% required, exactly the spec's "maker entry ~= 0.22%" column.
        $this->assertEqualsWithDelta(0.22, $costs['required_move_pct'], 1e-9);
        $this->assertEqualsWithDelta(0.0002, $costs['fee_rate_entry'], 1e-12);
        $this->assertEqualsWithDelta(0.0005, $costs['fee_rate_exit'], 1e-12);
    }

    public function test_account_fee_rate_is_preferred_over_config_and_is_labelled(): void
    {
        $costs = $this->model->costs('taker', 0.0004);

        $this->assertSame('account', $costs['fee_rate_source']);
        $this->assertEqualsWithDelta(0.0004, $costs['fee_rate_exit'], 1e-12);
        $this->assertEqualsWithDelta(0.0004 * 2 * 100 + 0.04, $costs['cost_pct'], 1e-9);
    }

    /**
     * The spec's §0.1 table, verbatim.
     *
     * @return array<string, array{float, int, float}>  [atrPct, horizonCandles, expectedMaxMovePct]
     */
    public static function specTableProvider(): array
    {
        return [
            '1m chart / 1 min expiry'  => [0.076, 1, 0.042],
            '1m chart / 5 min expiry'  => [0.076, 5, 0.093],
            '5m chart / 5 min expiry'  => [0.256, 1, 0.141],
            '5m chart / 15 min expiry' => [0.256, 3, 0.244],
            '5m chart / 30 min expiry' => [0.256, 6, 0.345],
            '5m chart / 60 min expiry' => [0.256, 12, 0.488],
        ];
    }

    /** @dataProvider specTableProvider */
    public function test_max_achievable_move_reproduces_the_spec_table(float $atrPct, int $horizonCandles, float $expected): void
    {
        $this->assertEqualsWithDelta(
            $expected,
            $this->model->maxAchievableMovePct($atrPct, $horizonCandles),
            0.0005,
        );
    }

    /** @dataProvider specTableProvider */
    public function test_spec_table_possible_column_matches_the_required_move(float $atrPct, int $horizonCandles, float $expectedMax): void
    {
        $required = $this->model->costs('taker')['required_move_pct'];
        $score = $this->model->minimumViableScore($atrPct, $horizonCandles, 'taker');

        if ($expectedMax < $required) {
            // "Never" rows in the table.
            $this->assertNull($score);

            return;
        }

        $this->assertNotNull($score);
        $this->assertEqualsWithDelta($required / $expectedMax, $score, 0.01);
    }

    public function test_horizon_candles_matches_the_forecast_formula(): void
    {
        $this->assertSame(1, $this->model->horizonCandles(60, 60));
        $this->assertSame(5, $this->model->horizonCandles(300, 60));
        $this->assertSame(3, $this->model->horizonCandles(900, 300));
        $this->assertSame(12, $this->model->horizonCandles(3600, 300));
        // A 15m chart with a 60s expiry still counts as 1, never 0.
        $this->assertSame(1, $this->model->horizonCandles(60, 900));
    }

    // ---- C4: the binding constraint --------------------------------------

    public function test_binding_constraint_is_duration_when_the_percentage_gate_cannot_be_cleared(): void
    {
        // 5m chart / 5 min expiry: max 0.141% < required 0.28%.
        $max = $this->model->maxAchievableMovePct(0.256, 1);
        $result = $this->model->bindingConstraint($max, 0.10, 5.0);

        $this->assertSame('duration', $result['constraint']);
        $this->assertStringContainsString('longer duration', $result['hint']);
        $this->assertStringContainsString('bigger stake cannot help', $result['hint']);
    }

    public function test_binding_constraint_is_stake_only_when_the_absolute_floor_is_what_fails(): void
    {
        // Percentage edge is fine (max and expected both clear 0.28%), but the
        // expected net in USDT is under the 0.03 floor -- the ONE case where a
        // bigger stake genuinely helps.
        $result = $this->model->bindingConstraint(1.0, 0.5, 0.001);

        $this->assertSame('stake', $result['constraint']);
        $this->assertStringContainsString('larger stake', $result['hint']);
    }

    public function test_binding_constraint_is_signal_when_the_duration_could_work_but_the_read_is_weak(): void
    {
        $result = $this->model->bindingConstraint(1.0, 0.05, 5.0);

        $this->assertSame('signal', $result['constraint']);
        $this->assertStringContainsString('too weak', $result['hint']);
    }

    public function test_binding_constraint_is_none_when_everything_clears(): void
    {
        $this->assertSame('none', $this->model->bindingConstraint(1.0, 0.5, 5.0)['constraint']);
    }

    // ---- C5: maker_entry is paper/shadow only ----------------------------

    public function test_maker_entry_is_refused_in_live_mode(): void
    {
        config()->set('quick_trade.allow_maker_entry_in_live', false);

        $this->assertSame('taker', $this->model->resolveFeeModelForMode('live', 'maker_entry'));
        $this->assertSame('maker_entry', $this->model->resolveFeeModelForMode('paper', 'maker_entry'));
    }

    public function test_maker_entry_is_allowed_in_live_only_behind_an_explicit_flag(): void
    {
        config()->set('quick_trade.allow_maker_entry_in_live', true);

        $this->assertSame('maker_entry', $this->model->resolveFeeModelForMode('live', 'maker_entry'));
    }

    // ---- C2/C3: duration availability ------------------------------------

    public function test_live_duration_availability_disables_impossible_durations_with_the_real_numbers(): void
    {
        config()->set('quick_trade.allow_short_live_expiry', true); // isolate the volatility rule
        config()->set('quick_trade.min_horizon_candles', 1);

        $rows = collect($this->model->durationAvailability([300, 1800, 3600], 0.256, 300, 'live'))
            ->keyBy('expiry_seconds');

        // 5m chart / 5 min: max 0.141% < 0.28% required -> disabled.
        $this->assertFalse($rows[300]['available']);
        $this->assertEqualsWithDelta(0.141, $rows[300]['max_achievable_move_pct'], 0.0005);
        $this->assertStringContainsString('0.141', $rows[300]['reason']);
        $this->assertStringContainsString('0.280', $rows[300]['reason']);

        // 5m chart / 30 min and / 60 min clear it.
        $this->assertTrue($rows[1800]['available']);
        $this->assertTrue($rows[3600]['available']);
    }

    public function test_short_live_expiries_are_hidden_unless_explicitly_allowed(): void
    {
        config()->set('quick_trade.allow_short_live_expiry', false);

        $rows = collect($this->model->durationAvailability([60, 3600], 0.256, 300, 'live'))->keyBy('expiry_seconds');
        $this->assertFalse($rows[60]['available']);
        $this->assertTrue($rows[60]['short_live_expiry']);

        // Paper mode keeps every duration.
        $paper = collect($this->model->durationAvailability([60, 3600], 0.256, 300, 'paper'))->keyBy('expiry_seconds');
        $this->assertTrue($paper[60]['available']);
    }

    public function test_shortest_viable_expiry_is_computed_not_hardcoded(): void
    {
        // 5m chart at ATR 0.256%: 5m impossible, 30m and 60m possible.
        $this->assertSame(1800, $this->model->shortestViableExpirySeconds([300, 1800, 3600], 0.256, 300));

        // At a volatility where nothing works, say so rather than guessing.
        $this->assertNull($this->model->shortestViableExpirySeconds([60, 300], 0.005, 60));
    }
}
