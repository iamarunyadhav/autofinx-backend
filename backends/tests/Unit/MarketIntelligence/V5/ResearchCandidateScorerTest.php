<?php

namespace Tests\Unit\MarketIntelligence\V5;

use App\Domain\MarketIntelligence\V5\Application\ResearchCandidateScorer;
use Tests\TestCase;

final class ResearchCandidateScorerTest extends TestCase
{
    public function test_it_accepts_liquid_aligned_closed_candle_data_without_claiming_profitability(): void
    {
        $result = (new ResearchCandidateScorer)->score(['opportunity_score' => 0.9], $this->assessment());

        $this->assertTrue($result['eligible']);
        $this->assertSame('UP', $result['direction']);
        $this->assertSame('LIQUID_ALIGNED_CLOSED_CANDLE_SETUP', $result['reason']);
        $this->assertGreaterThan(0.45, $result['score']);
    }

    public function test_it_rejects_unusable_or_conflicting_candle_data(): void
    {
        $assessment = $this->assessment();
        $assessment['horizons']['15m']['status'] = 'missing';

        $result = (new ResearchCandidateScorer)->score(['opportunity_score' => 1.0], $assessment);

        $this->assertFalse($result['eligible']);
        $this->assertSame('INSUFFICIENT_CLOSED_CANDLES', $result['reason']);
    }

    /** @return array<string, array<string, array<string, float|string>>> */
    private function assessment(): array
    {
        return ['horizons' => [
            '15m' => ['status' => 'observed', 'direction' => 'UP', 'volatility' => 0.004, 'observed_move' => 0.006],
            '1h' => ['status' => 'observed', 'direction' => 'UP', 'volatility' => 0.008, 'observed_move' => 0.012],
            '4h' => ['status' => 'observed', 'direction' => 'UP', 'volatility' => 0.014, 'observed_move' => 0.021],
        ]];
    }
}
