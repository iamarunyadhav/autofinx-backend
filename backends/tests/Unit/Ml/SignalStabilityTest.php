<?php

namespace Tests\Unit\Ml;

use App\Domain\Ml\Shadow\SignalStabilityService;
use App\Models\MlShadowPrediction;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class SignalStabilityTest extends TestCase
{
    private const STEP = 300_000;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ml.stability.confirm_predictions' => 2, 'ml.stability.exit_probability' => 0.50, 'ml.base_interval_seconds' => 300]);
    }

    /**
     * @param  list<array{0:string, 1:float, 2:float}>  $steps  [decision, p_buy, p_sell], 5 minutes apart
     * @return Collection<int, MlShadowPrediction>
     */
    private function rows(array $steps, int $gapAfter = -1): Collection
    {
        $t = now('UTC')->getTimestampMs() - count($steps) * self::STEP;

        return collect($steps)->map(function (array $step, int $i) use (&$t, $gapAfter) {
            $t += $i === $gapAfter + 1 && $gapAfter >= 0 ? 6 * self::STEP : self::STEP;

            return new MlShadowPrediction(['anchor_time_ms' => $t, 'decision' => $step[0], 'p_buy' => $step[1], 'p_sell' => $step[2]]);
        });
    }

    private function replay(array $steps, int $gapAfter = -1): array
    {
        return app(SignalStabilityService::class)->replay($this->rows($steps, $gapAfter));
    }

    public function test_a_side_needs_two_consecutive_decisions(): void
    {
        $this->assertSame('WAIT', $this->replay([['WAIT', .4, .2], ['BUY', .56, .1]])['state']);
        $this->assertSame('BUY', $this->replay([['BUY', .56, .1], ['BUY', .57, .1]])['state']);
        $this->assertSame('WAIT', $this->replay([['BUY', .56, .1], ['WAIT', .54, .1], ['BUY', .56, .1]])['state']); // never 2 in a row
    }

    public function test_hysteresis_holds_a_side_while_probability_stays_above_the_exit_level(): void
    {
        // The ETH case: 55% BUY, then 54/52% (raw WAIT) — the stable signal stays BUY.
        $state = $this->replay([['BUY', .56, .1], ['BUY', .57, .1], ['WAIT', .54, .1], ['WAIT', .52, .1], ['BUY', .55, .1]]);
        $this->assertSame('BUY', $state['state']);
        $this->assertSame(2, $state['raw_flips']);                    // raw flickered, stable did not
        $this->assertGreaterThanOrEqual(20, $state['held_minutes']);   // since the first confirming BUY

        // Two readings under 50% end it.
        $this->assertSame('WAIT', $this->replay([['BUY', .56, .1], ['BUY', .57, .1], ['WAIT', .46, .1], ['WAIT', .45, .1]])['state']);
        $this->assertSame('BUY', $this->replay([['BUY', .56, .1], ['BUY', .57, .1], ['WAIT', .46, .1], ['WAIT', .51, .1]])['state']);
    }

    public function test_flip_to_the_opposite_side_needs_its_own_confirmation(): void
    {
        $this->assertSame('BUY', $this->replay([['BUY', .6, .1], ['BUY', .6, .1], ['SELL', .5, .6]])['state']);
        $this->assertSame('SELL', $this->replay([['BUY', .6, .1], ['BUY', .6, .1], ['SELL', .1, .6], ['SELL', .1, .6]])['state']);
    }

    public function test_a_gap_in_predictions_resets_to_wait(): void
    {
        $state = $this->replay([['BUY', .6, .1], ['BUY', .6, .1], ['BUY', .6, .1]], gapAfter: 1);
        $this->assertSame('WAIT', $state['state']);                     // only 1 BUY since the gap
    }
}
