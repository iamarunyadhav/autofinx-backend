<?php

namespace Tests\Unit\MarketOpportunityBrain\Validation;

use App\Domain\MarketOpportunityBrain\Validation\ExperimentRunner;
use PHPUnit\Framework\TestCase;

class ExperimentRunnerTest extends TestCase
{
    private function runner(): ExperimentRunner
    {
        return new ExperimentRunner(['fee_bps_per_side' => 5, 'slippage_bps_per_side' => 2, 'funding_bps_per_trade' => 1]);
    }

    private function samples(string $arm, int $n, float $ret, string $side = 'LONG', string $sym = 'FIXTUREAUSDT'): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $t = 1000 + $i * 100;
            $out[] = ['arm' => $arm, 'symbol' => $sym, 'as_of' => $t, 'known_by' => $t, 'label_available_at' => $t + 50,
                'side' => $side, 'gross_return' => $ret + (($i % 2) ? 0.0001 : -0.0001), 'holdout' => false];
        }

        return $out;
    }

    public function test_insufficient_data_below_min_trades(): void
    {
        $r = $this->runner()->run($this->samples('A', 5, 0.01));
        $this->assertSame('INSUFFICIENT_DATA', $r['arms']['A']['verdict']);
        $this->assertFalse($r['execution_authorized']);
    }

    public function test_fee_funding_slippage_can_reject_gross_positive(): void
    {
        // round trip cost = 2*(7)+1 = 15 bps = 0.0015; gross 0.001 is positive but net negative
        $r = $this->runner()->run($this->samples('A', 40, 0.001));
        $this->assertTrue($r['arms']['A']['gross_positive_but_net_rejected']);
        $this->assertSame('REJECTED_NO_EDGE', $r['arms']['A']['verdict']);
    }

    public function test_clear_synthetic_positive_is_only_research_candidate_and_cost_stress_flips_it(): void
    {
        $s = $this->samples('A', 40, 0.01);
        $this->assertSame('CANDIDATE_POSITIVE_RESEARCH_ONLY', $this->runner()->run($s)['arms']['A']['verdict']);
        $this->assertSame('REJECTED_NO_EDGE', $this->runner()->run($s, 10.0)['arms']['A']['verdict']);
    }

    public function test_wait_samples_are_not_trades(): void
    {
        $r = $this->runner()->run($this->samples('B', 40, 0.01, 'WAIT'));
        $this->assertSame(0, $r['arms']['B']['trades']);
        $this->assertSame('INSUFFICIENT_DATA', $r['arms']['B']['verdict']);
    }

    public function test_no_future_feature_known_at_after_decision(): void
    {
        $s = $this->samples('A', 1, 0.01);
        $s[0]['known_by'] = $s[0]['as_of'] + 1;
        $this->expectException(\InvalidArgumentException::class);
        $this->runner()->run($s);
    }

    public function test_label_must_mature_after_decision(): void
    {
        $s = $this->samples('A', 1, 0.01);
        $s[0]['label_available_at'] = $s[0]['as_of'];
        $this->expectException(\InvalidArgumentException::class);
        $this->runner()->run($s);
    }

    public function test_sealed_holdout_rejected(): void
    {
        $s = $this->samples('A', 1, 0.01);
        $s[0]['holdout'] = true;
        $this->expectException(\InvalidArgumentException::class);
        $this->runner()->run($s);
    }

    public function test_duplicate_pnl_rejected(): void
    {
        $s = $this->samples('A', 1, 0.01);
        $this->expectException(\InvalidArgumentException::class);
        $this->runner()->run([$s[0], $s[0]]);
    }

    public function test_overlapping_horizon_dropped(): void
    {
        $a = $this->samples('A', 1, 0.01)[0];
        $b = $a;
        $b['as_of'] = $a['as_of'] + 10;
        $b['known_by'] = $b['as_of'];
        $b['label_available_at'] = $b['as_of'] + 50;
        $r = $this->runner()->run([$a, $b]);
        $this->assertSame(1, $r['arms']['A']['samples']);
    }

    public function test_deterministic_and_plan_hash_order_independent(): void
    {
        $s = $this->samples('A', 40, 0.01);
        $this->assertSame($this->runner()->run($s), $this->runner()->run($s));
        $this->assertSame(ExperimentRunner::planHash(['b' => 1, 'a' => 2]), ExperimentRunner::planHash(['a' => 2, 'b' => 1]));
    }
}
