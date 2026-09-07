<?php

namespace Tests\Feature\RatioBot;

use App\Domain\RatioBot\RatioBrain;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use App\Models\RatioBotLot;
use App\Models\RatioBotState;
use App\Models\RatioBrainDecisionLog;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Facade-level tests. §2.5's "neither condition alone is sufficient" is
 * covered here directly: guard-allows-but-trend-not-bearish and
 * trend-bearish-but-guard-denies both stay HOLD_RECOVERY; only both
 * together produce CONTROLLED_LOSS_EXIT.
 */
class RatioBrainTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::factory()->create();
    }

    public function test_a_flat_healthy_bot_with_no_recovery_and_no_emergency_returns_none(): void
    {
        $config = $this->makeBot();
        $this->seedFlatHistory($config);
        $state = RatioBotState::create(['ratio_bot_config_id' => $config->id, 'position_state' => RatioBotState::STATE_FLAT]);

        $result = app(RatioBrain::class)->evaluate($config, $state, 100.0);

        $this->assertSame(RatioBrain::ACTION_NONE, $result['action']);
        $this->assertDatabaseHas('ratio_brain_decision_logs', ['ratio_bot_config_id' => $config->id, 'action' => RatioBrain::ACTION_NONE]);
    }

    public function test_a_spot_flash_crash_outside_recovery_returns_emergency_active(): void
    {
        $config = $this->makeBot(['emergency_atr_multiple_threshold' => 3.0]);
        $this->seedFlatHistory($config, price: 100.0);
        $state = RatioBotState::create(['ratio_bot_config_id' => $config->id, 'position_state' => RatioBotState::STATE_HOLDING]);

        $result = app(RatioBrain::class)->evaluate($config, $state, currentPrice: 80.0);

        $this->assertSame(RatioBrain::ACTION_EMERGENCY_ACTIVE, $result['action']);
        $this->assertTrue((bool) $result['component_breakdown']['emergency']['is_emergency']);
    }

    public function test_a_futures_bot_is_never_flagged_by_the_spot_emergency_guard(): void
    {
        $config = $this->makeBot(['market_type' => 'futures']);
        $state = RatioBotState::create(['ratio_bot_config_id' => $config->id, 'position_state' => RatioBotState::STATE_HOLDING]);

        $result = app(RatioBrain::class)->evaluate($config, $state, currentPrice: 80.0);

        $this->assertSame(RatioBrain::ACTION_NONE, $result['action']);
        $this->assertFalse((bool) $result['component_breakdown']['emergency']['is_emergency']);
    }

    public function test_guard_allows_but_trend_is_not_bearish_stays_hold_recovery(): void
    {
        $config = $this->makeBot();
        $this->seedUptrend($config); // trend direction will be bullish, not bearish
        $state = $this->stuckState($config, lastBuyPrice: 100.0);
        $this->closeLot($config, realizedPnl: 50.0);

        $result = app(RatioBrain::class)->evaluate($config, $state, currentPrice: 95.0);

        $this->assertSame(RatioBrain::ACTION_HOLD_RECOVERY, $result['action']);
        $this->assertTrue((bool) $result['component_breakdown']['portfolio_guard']['allowed']);
        $this->assertFalse((bool) $result['component_breakdown']['trend_unfavorable']);
    }

    public function test_trend_is_bearish_but_guard_denies_stays_hold_recovery(): void
    {
        $config = $this->makeBot();
        $this->seedDowntrend($config); // trend direction will be bearish
        $state = $this->stuckState($config, lastBuyPrice: 100.0);
        // No closed lots at all -- zero realized cycle profit, guard must deny.

        $result = app(RatioBrain::class)->evaluate($config, $state, currentPrice: 95.0);

        $this->assertSame(RatioBrain::ACTION_HOLD_RECOVERY, $result['action']);
        $this->assertFalse((bool) $result['component_breakdown']['portfolio_guard']['allowed']);
        $this->assertTrue((bool) $result['component_breakdown']['trend_unfavorable']);
    }

    public function test_guard_allows_and_trend_is_bearish_recommends_controlled_loss_exit(): void
    {
        $config = $this->makeBot();
        $this->seedDowntrend($config);
        $state = $this->stuckState($config, lastBuyPrice: 100.0);
        $this->closeLot($config, realizedPnl: 50.0);

        $result = app(RatioBrain::class)->evaluate($config, $state, currentPrice: 95.0);

        $this->assertSame(RatioBrain::ACTION_CONTROLLED_LOSS_EXIT, $result['action']);
        $log = RatioBrainDecisionLog::where('ratio_bot_config_id', $config->id)->latest('occurred_at')->first();
        $this->assertNotNull($log);
        $this->assertSame(RatioBrain::ACTION_CONTROLLED_LOSS_EXIT, $log->action);
        $this->assertNotNull($log->portfolio_guard_result);
    }

    public function test_cash_stuck_recovery_monitoring_never_produces_a_controlled_loss_exit(): void
    {
        // OUTCOME_PAUSE only applies to recoveryMode='position_stuck' inside
        // RecoveryReviewService -- CASH_RECOVERY_MONITORING (recoveryMode=
        // 'cash_stuck') has nothing to "exit at a loss" (there's no open
        // position), so RatioBrain must never attempt a controlled-loss-exit
        // for it even if the underlying review somehow paused.
        $config = $this->makeBot();
        $this->seedDowntrend($config);
        $state = RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_CASH_RECOVERY_MONITORING,
            'last_buy_price' => 0,
            'current_cycle_number' => 1,
        ]);
        $this->closeLot($config, realizedPnl: 50.0);

        $result = app(RatioBrain::class)->evaluate($config, $state, currentPrice: 95.0);

        $this->assertNotSame(RatioBrain::ACTION_CONTROLLED_LOSS_EXIT, $result['action']);
    }

    private function makeBot(array $overrides = []): RatioBotConfig
    {
        return RatioBotConfig::create(array_merge([
            'workspace_id' => $this->workspace->id,
            'symbol' => 'BTCUSDT',
            'exchange' => 'binance',
            'market_type' => 'spot',
            'timeframe' => '1m',
            'algorithm' => 'ratio_threshold',
            'execution_mode' => 'sandbox',
            'sell_threshold_ratio' => 0.02,
            'buy_threshold_ratio' => 0.01,
            'v2_enabled' => true,
            'recovery_enabled' => true,
            'recovery_timeout_minutes' => 60,
            'enabled' => true,
        ], $overrides));
    }

    private function stuckState(RatioBotConfig $config, float $lastBuyPrice): RatioBotState
    {
        return RatioBotState::create([
            'ratio_bot_config_id' => $config->id,
            'position_state' => RatioBotState::STATE_RECOVERY_MONITORING,
            'last_buy_price' => $lastBuyPrice,
            'last_buy_quantity' => 1.0,
            'current_cycle_number' => 1,
        ]);
    }

    private function closeLot(RatioBotConfig $config, float $realizedPnl): void
    {
        RatioBotLot::create([
            'ratio_bot_config_id' => $config->id,
            'cycle_number' => 1,
            'buy_stage_index' => 1,
            'lot_type' => RatioBotLot::TYPE_NORMAL,
            'buy_price' => 90.0,
            'original_qty' => 1.0,
            'remaining_qty' => 0.0,
            'spent_quote' => 90.0,
            'status' => RatioBotLot::STATUS_CLOSED,
            'realized_pnl' => $realizedPnl,
            'bought_at' => now()->subHours(2),
            'closed_at' => now()->subHour(),
        ]);
    }

    private function seedFlatHistory(RatioBotConfig $config, float $price = 100.0): void
    {
        $this->seedCandles($config, count: 50, start: $price, step: 0.0);
    }

    private function seedUptrend(RatioBotConfig $config): void
    {
        $this->seedCandles($config, count: 50, start: 90.0, step: 0.3);
    }

    private function seedDowntrend(RatioBotConfig $config): void
    {
        $this->seedCandles($config, count: 50, start: 110.0, step: -0.3);
    }

    private function seedCandles(RatioBotConfig $config, int $count, float $start, float $step): void
    {
        $time = now()->subMinutes($count + 1);
        for ($i = 0; $i < $count; $i++) {
            $open = $start + ($step * $i);
            $close = $open + $step;
            MarketOhlc::create([
                'exchange' => $config->exchangeKey(),
                'symbol' => $config->normalizedSymbol(),
                'timeframe' => $config->timeFrame(),
                'open' => $open,
                'high' => max($open, $close) + 0.2,
                'low' => min($open, $close) - 0.2,
                'close' => $close,
                'volume' => 10,
                'ts' => $time->copy()->addMinutes($i),
            ]);
        }
    }
}
