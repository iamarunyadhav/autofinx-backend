<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\PortfolioProfitGuard;
use App\Models\RatioBotConfig;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RatioBrain spec §2.5 acceptance tests: a cycle with prior realized profit
 * that comfortably covers a candidate loss is allowed; a cycle where the
 * loss would breach the buffer is not; a fresh cycle with zero realized
 * profit and one losing lot is never allowed to force a loss.
 */
class PortfolioProfitGuardTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
    }

    public function test_a_cycle_with_ample_realized_profit_allows_a_bounded_loss(): void
    {
        $config = $this->makeBot();
        $this->closeLot($config, realizedPnl: 20.0);

        $result = app(PortfolioProfitGuard::class)->allows($config, candidateLossUsdt: -5.0, unrealizedOtherLotsUsdt: 0.0, currentPrice: 95.0);

        $this->assertTrue($result['allowed']);
        $this->assertEqualsWithDelta(20.0, $result['realized_cycle_profit'], 0.0001);
        $this->assertEqualsWithDelta(5.0, $result['candidate_loss'], 0.0001);
    }

    public function test_a_loss_that_would_breach_the_buffer_is_not_allowed(): void
    {
        $config = $this->makeBot();
        $this->closeLot($config, realizedPnl: 2.0);

        $result = app(PortfolioProfitGuard::class)->allows($config, candidateLossUsdt: -10.0, unrealizedOtherLotsUsdt: 0.0, currentPrice: 95.0);

        $this->assertFalse($result['allowed']);
    }

    public function test_a_fresh_cycle_with_zero_realized_profit_never_forces_a_loss(): void
    {
        $config = $this->makeBot();
        // No closed lots at all -- realized_cycle_profit is 0.

        $result = app(PortfolioProfitGuard::class)->allows($config, candidateLossUsdt: -1.0, unrealizedOtherLotsUsdt: 0.0, currentPrice: 95.0);

        $this->assertFalse($result['allowed']);
        $this->assertEqualsWithDelta(0.0, $result['realized_cycle_profit'], 0.0001);
    }

    public function test_only_the_current_cycles_closed_lots_count_toward_realized_profit(): void
    {
        $config = $this->makeBot();
        $config->state()->first()->update(['current_cycle_number' => 2]);
        $config = $config->fresh('state'); // the earlier `state()->first()` call left a stale cached relation

        // A prior cycle's huge profit must never leak into the current
        // cycle's guard check -- each cycle is its own accounting unit.
        RatioBotLot::create($this->lotAttrs($config, cycleNumber: 1, status: RatioBotLot::STATUS_CLOSED, realizedPnl: 500.0));
        $this->closeLot($config, realizedPnl: 1.0, cycleNumber: 2);

        $result = app(PortfolioProfitGuard::class)->allows($config, candidateLossUsdt: -10.0, unrealizedOtherLotsUsdt: 0.0, currentPrice: 95.0);

        $this->assertFalse($result['allowed']);
        $this->assertEqualsWithDelta(1.0, $result['realized_cycle_profit'], 0.0001);
    }

    public function test_required_buffer_scales_with_configured_min_profit_and_safety_margin(): void
    {
        $config = $this->makeBot([
            'portfolio_guard_min_profit_usdt' => 1.0,
            'portfolio_guard_safety_margin_pct' => 50.0,
            'fee_bps' => 0,
            'max_slippage_bps' => 0,
        ]);
        $this->closeLot($config, realizedPnl: 1.4);

        // required_buffer = (0 costs + 1.0 min_profit) * 1.5 margin = 1.5.
        // projected = 1.4 - 0 candidate loss = 1.4 < 1.5 -> not allowed.
        $result = app(PortfolioProfitGuard::class)->allows($config, candidateLossUsdt: 0.0, unrealizedOtherLotsUsdt: 0.0, currentPrice: 95.0);

        $this->assertEqualsWithDelta(1.5, $result['required_buffer'], 0.0001);
        $this->assertFalse($result['allowed']);
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

        RatioBotState::create(['ratio_bot_config_id' => $config->id, 'position_state' => RatioBotState::STATE_RECOVERY_MONITORING, 'current_cycle_number' => 1]);

        return $config->fresh('state');
    }

    private function closeLot(RatioBotConfig $config, float $realizedPnl, int $cycleNumber = 1): void
    {
        RatioBotLot::create($this->lotAttrs($config, $cycleNumber, RatioBotLot::STATUS_CLOSED, $realizedPnl));
    }

    private function lotAttrs(RatioBotConfig $config, int $cycleNumber, string $status, float $realizedPnl): array
    {
        return [
            'ratio_bot_config_id' => $config->id,
            'cycle_number' => $cycleNumber,
            'buy_stage_index' => 1,
            'lot_type' => RatioBotLot::TYPE_NORMAL,
            'buy_price' => 90.0,
            'original_qty' => 1.0,
            'remaining_qty' => $status === RatioBotLot::STATUS_CLOSED ? 0.0 : 1.0,
            'spent_quote' => 90.0,
            'status' => $status,
            'realized_pnl' => $realizedPnl,
            'bought_at' => now(),
            'closed_at' => $status === RatioBotLot::STATUS_CLOSED ? now() : null,
        ];
    }
}
