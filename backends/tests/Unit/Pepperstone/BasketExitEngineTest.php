<?php

namespace Tests\Unit\Pepperstone;

use App\Domain\Pepperstone\BasketExitEngine;
use App\Domain\Pepperstone\MarketRegimeDetector;
use App\Models\PepperstoneBasket;
use Tests\TestCase;

/**
 * One test per exit reason, each triggered in isolation -- every other
 * input defaulted to a value that would NOT itself trigger a close (same
 * discipline as RiskEngineTest).
 */
class BasketExitEngineTest extends TestCase
{
    private function basket(array $overrides = []): PepperstoneBasket
    {
        return new PepperstoneBasket(array_merge([
            'direction' => PepperstoneBasket::DIRECTION_BUY,
            'status' => PepperstoneBasket::STATUS_ACTIVE,
            'maximum_allowed_loss' => 100.0,
            'take_profit_target' => 200.0,
        ], $overrides));
    }

    private function neutralMtf(): array
    {
        return ['direction' => 'NEUTRAL', 'confidence' => 0.0];
    }

    /** @test */
    public function does_not_close_a_perfectly_ordinary_basket(): void
    {
        $result = (new BasketExitEngine())->evaluate($this->basket(), $this->neutralMtf(), MarketRegimeDetector::REGIME_TRENDING);

        $this->assertFalse($result['should_close']);
        $this->assertNull($result['reason']);
    }

    /** @test */
    public function closes_on_hard_stop_loss_only(): void
    {
        $result = (new BasketExitEngine())->evaluate(
            $this->basket(),
            $this->neutralMtf(),
            MarketRegimeDetector::REGIME_TRENDING,
            ['floating_pnl' => -150.0],
        );

        $this->assertTrue($result['should_close']);
        $this->assertSame(BasketExitEngine::REASON_HARD_STOP_LOSS, $result['reason']);
    }

    /** @test */
    public function closes_on_take_profit_only(): void
    {
        $result = (new BasketExitEngine())->evaluate(
            $this->basket(),
            $this->neutralMtf(),
            MarketRegimeDetector::REGIME_TRENDING,
            ['floating_pnl' => 250.0],
        );

        $this->assertTrue($result['should_close']);
        $this->assertSame(BasketExitEngine::REASON_TAKE_PROFIT, $result['reason']);
    }

    /** @test */
    public function closes_on_v3_direction_reversal_only(): void
    {
        $result = (new BasketExitEngine())->evaluate(
            $this->basket(['direction' => PepperstoneBasket::DIRECTION_BUY]),
            $this->neutralMtf(),
            MarketRegimeDetector::REGIME_TRENDING,
            ['v3_direction' => 'SELL'],
        );

        $this->assertTrue($result['should_close']);
        $this->assertSame(BasketExitEngine::REASON_V3_REVERSAL, $result['reason']);
    }

    /** @test */
    public function closes_on_mtf_conflict_only(): void
    {
        $result = (new BasketExitEngine())->evaluate(
            $this->basket(['direction' => PepperstoneBasket::DIRECTION_BUY]),
            ['direction' => 'SELL', 'confidence' => 0.8],
            MarketRegimeDetector::REGIME_TRENDING,
        );

        $this->assertTrue($result['should_close']);
        $this->assertSame(BasketExitEngine::REASON_MTF_CONFLICT, $result['reason']);
    }

    /** @test */
    public function closes_on_regime_chaotic_only(): void
    {
        $result = (new BasketExitEngine())->evaluate(
            $this->basket(),
            $this->neutralMtf(),
            MarketRegimeDetector::REGIME_CHAOTIC,
        );

        $this->assertTrue($result['should_close']);
        $this->assertSame(BasketExitEngine::REASON_REGIME_CHAOTIC, $result['reason']);
    }

    /** @test */
    public function closes_on_margin_deterioration_only(): void
    {
        $result = (new BasketExitEngine())->evaluate(
            $this->basket(),
            $this->neutralMtf(),
            MarketRegimeDetector::REGIME_TRENDING,
            ['margin_level_pct' => 50.0, 'margin_level_floor_pct' => 100.0],
        );

        $this->assertTrue($result['should_close']);
        $this->assertSame(BasketExitEngine::REASON_MARGIN_DETERIORATION, $result['reason']);
    }

    /** @test */
    public function closes_on_stale_feed_or_broker_issue_only(): void
    {
        $result = (new BasketExitEngine())->evaluate(
            $this->basket(),
            $this->neutralMtf(),
            MarketRegimeDetector::REGIME_TRENDING,
            ['feed_stale' => true],
        );

        $this->assertTrue($result['should_close']);
        $this->assertSame(BasketExitEngine::REASON_STALE_FEED_OR_BROKER_ISSUE, $result['reason']);
    }

    /** @test */
    public function hard_stop_loss_wins_over_every_other_simultaneously_true_reason(): void
    {
        $result = (new BasketExitEngine())->evaluate(
            $this->basket(['direction' => PepperstoneBasket::DIRECTION_BUY]),
            ['direction' => 'SELL', 'confidence' => 0.9],
            MarketRegimeDetector::REGIME_CHAOTIC,
            [
                'floating_pnl' => -150.0,
                'v3_direction' => 'SELL',
                'feed_stale' => true,
            ],
        );

        $this->assertSame(BasketExitEngine::REASON_HARD_STOP_LOSS, $result['reason']);
    }
}
