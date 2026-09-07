<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\ScoredSellCandidateSelector;
use App\Models\RatioBotConfig;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RatioBrain spec §4.4 acceptance test: BUY1=100, BUY2=90, BUY3=80,
 * current=95 -> BUY3 (larger profit) ranks above BUY2; a tie-breaking
 * scenario confirms age acts only as a tie-breaker, not the primary sort.
 */
class ScoredSellCandidateSelectorTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
    }

    public function test_the_larger_profit_lot_ranks_above_the_smaller_profit_lot(): void
    {
        $config = $this->makeBot();
        // BUY1=100 is deliberately NEVER included in the eligible set -- this
        // mirrors the spec's own scenario (the profit gate already excluded
        // it upstream in profitableFifoSellPlan(), the selector never sees it).
        $buy2 = $this->makeLot($config, buyPrice: 90.0, stageIndex: 2, boughtMinutesAgo: 60);
        $buy3 = $this->makeLot($config, buyPrice: 80.0, stageIndex: 3, boughtMinutesAgo: 10);

        $ranked = app(ScoredSellCandidateSelector::class)->rank($config, [(string) $buy2->id, (string) $buy3->id], currentPrice: 95.0);

        $this->assertCount(2, $ranked);
        $this->assertSame((string) $buy3->id, $ranked[0]['lot_id'], 'BUY3 has the larger net profit % and should rank first');
        $this->assertSame((string) $buy2->id, $ranked[1]['lot_id']);
        $this->assertGreaterThan($ranked[1]['score'], $ranked[0]['score']);
    }

    public function test_equal_profit_lots_break_the_tie_by_age(): void
    {
        $config = $this->makeBot(['sell_selector_weights' => ['profit' => 1.0, 'capital_release' => 0.0, 'age' => 1.0, 'cycle_effect' => 0.0]]);
        $older = $this->makeLot($config, buyPrice: 90.0, stageIndex: 1, boughtMinutesAgo: 500);
        $newer = $this->makeLot($config, buyPrice: 90.0, stageIndex: 2, boughtMinutesAgo: 5);

        $ranked = app(ScoredSellCandidateSelector::class)->rank($config, [(string) $older->id, (string) $newer->id], currentPrice: 100.0);

        $this->assertSame((string) $older->id, $ranked[0]['lot_id'], 'with identical profit, the older lot should be the tie-breaking winner');
    }

    public function test_an_empty_eligible_set_ranks_to_an_empty_array(): void
    {
        $config = $this->makeBot();

        $ranked = app(ScoredSellCandidateSelector::class)->rank($config, [], currentPrice: 100.0);

        $this->assertSame([], $ranked);
    }

    public function test_closing_the_only_open_lot_gets_a_cycle_effect_boost(): void
    {
        $config = $this->makeBot(['sell_selector_weights' => ['profit' => 0.0, 'capital_release' => 0.0, 'age' => 0.0, 'cycle_effect' => 1.0]]);
        $onlyLot = $this->makeLot($config, buyPrice: 90.0, stageIndex: 1, boughtMinutesAgo: 5);

        $ranked = app(ScoredSellCandidateSelector::class)->rank($config, [(string) $onlyLot->id], currentPrice: 100.0);

        $this->assertTrue($ranked[0]['is_last_open_lot']);
        $this->assertEqualsWithDelta(100.0, $ranked[0]['score'], 0.0001);
    }

    private function makeBot(array $overrides = []): RatioBotConfig
    {
        $config = RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'execution_mode' => 'sandbox',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'enabled' => true,
        ], $overrides));

        RatioBotState::create(['ratio_bot_config_id' => $config->id, 'position_state' => RatioBotState::STATE_HOLDING, 'current_cycle_number' => 1]);

        return $config->fresh('state');
    }

    private function makeLot(RatioBotConfig $config, float $buyPrice, int $stageIndex, int $boughtMinutesAgo): RatioBotLot
    {
        return RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'cycle_number' => 1,
            'buy_stage_index' => $stageIndex,
            'lot_type' => RatioBotLot::TYPE_NORMAL,
            'buy_price' => $buyPrice,
            'original_qty' => 1.0,
            'remaining_qty' => 1.0,
            'spent_quote' => $buyPrice,
            'status' => RatioBotLot::STATUS_OPEN,
            'bought_at' => now()->subMinutes($boughtMinutesAgo),
        ]);
    }
}
