<?php

namespace Tests\Feature\MarketIntelligence;

use App\Domain\MarketIntelligence\IndicatorGroupScorer;
use Tests\TestCase;

/**
 * Unit tests for IndicatorGroupScorer (Unified Forecast Engine, Phase 4b) --
 * deterministic candle sequences, no database involved (uses the
 * candles-array entry point directly).
 */
class IndicatorGroupScorerTest extends TestCase
{
    public function test_momentum_group_averages_rsi_and_stochastic_instead_of_summing_them(): void
    {
        // A steady, strong uptrend: RSI and Stochastic will both read
        // strongly bullish (correlated, as they usually are) -- the group
        // vote must be their AVERAGE (bounded in [-1, 1]), never a sum that
        // would silently exceed what either indicator alone reports.
        $candles = $this->uptrendCandles(60, 100.0, 1.5);

        $scorer = new IndicatorGroupScorer();
        $result = $scorer->scoreCandles($candles);

        $this->assertTrue($result['momentum']['available']);
        $rsiVote = $result['momentum']['components']['rsi']['vote'];
        $stochVote = $result['momentum']['components']['stochastic']['vote'];

        // The group vote is bounded by the max of its members, not their sum.
        $this->assertLessThanOrEqual(max($rsiVote, $stochVote) + 0.0001, $result['momentum']['vote']);
        $this->assertEqualsWithDelta(($rsiVote + $stochVote) / 2, $result['momentum']['vote'], 0.0001);
        $this->assertGreaterThanOrEqual(-1.0, $result['momentum']['vote']);
        $this->assertLessThanOrEqual(1.0, $result['momentum']['vote']);
    }

    public function test_a_single_available_indicator_in_a_group_is_not_penalized_relative_to_two(): void
    {
        // 15 candles: enough for RSI (period 14, needs > 14 closes) but not
        // enough for Stochastic (needs kPeriod+dPeriod-1 = 16) -- momentum
        // only ever gets RSI here, not both RSI+Stochastic. The group's
        // vote should still reflect the one indicator's own reading, not
        // be diluted by an absent second one (averaging over AVAILABLE
        // members only, not a fixed divisor of "2").
        $candles = $this->uptrendCandles(15, 100.0, 1.5);

        $scorer = new IndicatorGroupScorer();
        $result = $scorer->scoreCandles($candles);

        $this->assertTrue($result['momentum']['available']);
        $this->assertArrayHasKey('rsi', $result['momentum']['components']);
        $this->assertArrayNotHasKey('stochastic', $result['momentum']['components']);
        $this->assertSame($result['momentum']['components']['rsi']['vote'], $result['momentum']['vote']);
    }

    public function test_combined_vote_averages_across_available_groups_not_the_count_of_indicators(): void
    {
        $candles = $this->uptrendCandles(60, 100.0, 1.5);

        $scorer = new IndicatorGroupScorer();
        $result = $scorer->scoreCandles($candles);

        $available = array_filter([
            $result['momentum'],
            $result['trend_confirmation'],
            $result['volatility_range'],
        ], fn (array $g) => $g['available']);

        $expected = round(array_sum(array_column($available, 'vote')) / count($available), 4);
        $this->assertSame($expected, $result['combined_vote']);
        $this->assertSame(count($available), $result['available_group_count']);
    }

    public function test_insufficient_candles_reports_groups_as_unavailable_not_a_fabricated_vote(): void
    {
        $candles = $this->uptrendCandles(5, 100.0, 1.0);

        $scorer = new IndicatorGroupScorer();
        $result = $scorer->scoreCandles($candles);

        $this->assertFalse($result['momentum']['available']);
        $this->assertFalse($result['trend_confirmation']['available']);
        $this->assertFalse($result['volatility_range']['available']);
        $this->assertSame(0, $result['available_group_count']);
        $this->assertSame('neutral', $result['combined_direction']);
        $this->assertSame(0.0, $result['combined_vote']);
    }

    /** @return array<int, array{open:float,high:float,low:float,close:float}> */
    private function uptrendCandles(int $count, float $start, float $step): array
    {
        $candles = [];
        $price = $start;
        for ($i = 0; $i < $count; $i++) {
            $open = $price;
            $close = $open + $step;
            $candles[] = [
                'open' => $open,
                'high' => $close + 0.5,
                'low' => $open - 0.5,
                'close' => $close,
            ];
            $price = $close;
        }

        return $candles;
    }
}
