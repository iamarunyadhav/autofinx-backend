<?php

namespace App\Domain\MarketOpportunityBrain\Validation;

/**
 * Phase 5 pure, offline experiment evaluator (RESEARCH_ONLY, no I/O, no execution path).
 *
 * Input samples are hypothetical replay outcomes. Output is NEVER a "validated edge": the strongest
 * verdict is CANDIDATE_POSITIVE_RESEARCH_ONLY, which still requires forward testing by the owner.
 *
 * Sample shape: arm, symbol, as_of (unix), known_by (unix), label_available_at (unix),
 * side (LONG|SHORT|WAIT), gross_return (decimal, signed in trade direction, e.g. 0.004),
 * holdout (bool, must be false; sealed holdout is never read).
 */
final class ExperimentRunner
{
    public const MIN_TRADES = 30;
    public const BOOTSTRAP_ITERATIONS = 1000;

    /** @param array{fee_bps_per_side:float|int, slippage_bps_per_side:float|int, funding_bps_per_trade:float|int} $cost */
    public function __construct(
        private readonly array $cost,
        private readonly int $seed = 42,
    ) {
    }

    /** Canonical immutable plan hash (recursively sorted keys). */
    public static function planHash(array $plan): string
    {
        $canon = static function ($v) use (&$canon) {
            if (is_array($v)) {
                if (! array_is_list($v)) {
                    ksort($v);
                }

                return array_map($canon, $v);
            }

            return $v;
        };

        return hash('sha256', json_encode($canon($plan), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function roundTripCostFraction(): float
    {
        return (2 * ($this->cost['fee_bps_per_side'] + $this->cost['slippage_bps_per_side'])
            + $this->cost['funding_bps_per_trade']) / 10000.0;
    }

    /** @param array<int,array<string,mixed>> $samples */
    public function run(array $samples, float $costStressMultiplier = 1.0): array
    {
        $this->assertCausal($samples);
        $samples = $this->dedupeAndDropOverlap($samples);

        $byArm = [];
        foreach ($samples as $s) {
            $byArm[$s['arm']][] = $s;
        }
        ksort($byArm);

        $arms = [];
        foreach ($byArm as $arm => $rows) {
            $arms[$arm] = $this->evaluateArm($rows, $costStressMultiplier);
        }

        return [
            'schema' => 'mob.experiment_result.v1',
            'label' => 'HYPOTHETICAL_REPLAY_NOT_LIVE_EDGE',
            'cost_stress_multiplier' => $costStressMultiplier,
            'round_trip_cost_fraction' => $this->roundTripCostFraction() * $costStressMultiplier,
            'seed' => $this->seed,
            'arms' => $arms,
            'execution_authorized' => false,
        ];
    }

    private function assertCausal(array $samples): void
    {
        foreach ($samples as $s) {
            if (($s['holdout'] ?? false) === true) {
                throw new \InvalidArgumentException('Sealed holdout samples must never be evaluated.');
            }
            if ($s['known_by'] > $s['as_of']) {
                throw new \InvalidArgumentException('Future leakage: feature known_by is after decision as_of.');
            }
            if ($s['label_available_at'] <= $s['as_of']) {
                throw new \InvalidArgumentException('Label must mature strictly after the decision time.');
            }
        }
    }

    /** Duplicate P&L guard; overlapping holding windows per (arm,symbol) are dropped, never double counted. */
    private function dedupeAndDropOverlap(array $samples): array
    {
        usort($samples, static fn ($a, $b) => [$a['arm'], $a['symbol'], $a['as_of']] <=> [$b['arm'], $b['symbol'], $b['as_of']]);
        $seen = [];
        $lastEnd = [];
        $kept = [];
        foreach ($samples as $s) {
            $key = $s['arm'].'|'.$s['symbol'].'|'.$s['as_of'];
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("Duplicate sample would double count P&L: {$key}");
            }
            $seen[$key] = true;
            $k = $s['arm'].'|'.$s['symbol'];
            if (isset($lastEnd[$k]) && $s['as_of'] < $lastEnd[$k]) {
                continue;
            }
            $lastEnd[$k] = $s['label_available_at'];
            $kept[] = $s;
        }

        return $kept;
    }

    private function evaluateArm(array $rows, float $stress): array
    {
        $cost = $this->roundTripCostFraction() * $stress;
        $net = [];
        $gross = [];
        $waits = 0;
        foreach ($rows as $s) {
            if (! in_array($s['side'], ['LONG', 'SHORT'], true)) {
                $waits++;

                continue;
            }
            $g = (float) $s['gross_return'];
            $gross[] = $g;
            $net[] = $g - $cost;
        }
        $n = count($net);
        $out = [
            'samples' => count($rows),
            'trades' => $n,
            'waits' => $waits,
            'gross_mean' => $n ? array_sum($gross) / $n : null,
            'net_mean' => $n ? array_sum($net) / $n : null,
            'net_hit_rate' => $n ? count(array_filter($net, static fn ($x) => $x > 0)) / $n : null,
            'net_ci95' => null,
            'gross_positive_but_net_rejected' => $n > 0 && array_sum($gross) > 0 && array_sum($net) <= 0,
        ];
        if ($n < self::MIN_TRADES) {
            $out['verdict'] = 'INSUFFICIENT_DATA';

            return $out;
        }
        $ci = $this->bootstrapCi($net);
        $out['net_ci95'] = $ci;
        if ($out['net_mean'] <= 0 || $ci[1] <= 0) {
            $out['verdict'] = 'REJECTED_NO_EDGE';
        } elseif ($ci[0] <= 0) {
            $out['verdict'] = 'INCONCLUSIVE';
        } else {
            $out['verdict'] = 'CANDIDATE_POSITIVE_RESEARCH_ONLY';
        }

        return $out;
    }

    /** Seeded percentile bootstrap of the mean (own LCG; independent of global RNG state). */
    private function bootstrapCi(array $xs): array
    {
        $n = count($xs);
        $state = $this->seed;
        $means = [];
        for ($i = 0; $i < self::BOOTSTRAP_ITERATIONS; $i++) {
            $sum = 0.0;
            for ($j = 0; $j < $n; $j++) {
                $state = ($state * 1103515245 + 12345) & 0x7fffffff;
                $sum += $xs[$state % $n];
            }
            $means[] = $sum / $n;
        }
        sort($means);

        return [$means[(int) floor(0.025 * self::BOOTSTRAP_ITERATIONS)], $means[(int) floor(0.975 * self::BOOTSTRAP_ITERATIONS) - 1]];
    }
}
