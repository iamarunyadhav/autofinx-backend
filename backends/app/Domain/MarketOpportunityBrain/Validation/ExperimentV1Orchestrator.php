<?php

namespace App\Domain\MarketOpportunityBrain\Validation;

use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicHistoryFetcher;
use App\Domain\MarketOpportunityBrain\Forecast\ChronosBatchRunner;

/**
 * Phase 5 experiment "mob-exp-v1" (RESEARCH_ONLY, hypothetical replay). Arms A (non-ML momentum
 * baseline) and C (Chronos-2 forecast, fee-aware threshold). The plan is hashed and written to disk
 * BEFORE any data is fetched or any forecast is produced; nothing in it is tuned after results.
 * Reads only public Binance klines strictly before the sealed holdout. Never authorizes execution.
 */
final class ExperimentV1Orchestrator
{
    public function __construct(
        private readonly BinancePublicHistoryFetcher $fetcher,
        private readonly ChronosBatchRunner $chronos,
    ) {
    }

    public static function plan(): array
    {
        return [
            'experiment_id' => 'mob-exp-v1',
            'symbols' => ['BTCUSDT', 'ETHUSDT', 'BNBUSDT', 'SOLUSDT', 'XRPUSDT'],
            'timeframe' => '1h',
            'context_bars' => 256,
            'horizon_bars' => 4,
            'step_bars' => 4,
            'window_start_utc' => '2025-07-01T00:00:00Z',
            'window_end_utc' => '2025-12-31T23:59:59Z',
            'seal_boundary_utc' => '2026-01-04T00:00:00Z',
            'arm_a' => 'momentum_sign_last_h_bars (non-ML stand-in; NOT the fixed-five TradingView baseline -> NOT_COMPARABLE with MASTER_SPEC arm A)',
            'arm_c' => 'chronos2 p50 vs entry; LONG/SHORT only if |p50/entry-1| > threshold else WAIT',
            'threshold_fraction' => 0.0012,
            'cost' => ['fee_bps_per_side' => 5, 'slippage_bps_per_side' => 1, 'funding_bps_per_trade' => 0],
            'cost_stress_multipliers' => [1.0, 2.0],
            'seed' => 42,
            'entry_assumption' => 'fill at as_of bar close, no latency (optimistic)',
            'verdict_rule' => 'ExperimentRunner v1: MIN_TRADES 30, bootstrap 95% CI on net mean; strongest verdict CANDIDATE_POSITIVE_RESEARCH_ONLY',
            'label' => 'HYPOTHETICAL_REPLAY_NOT_LIVE_EDGE',
            'execution_authorized' => false,
        ];
    }

    /** @return array<string,mixed> */
    public function run(string $outDir, ?int $maxWindowsPerSymbol = null): array
    {
        if (! is_dir($outDir) && ! mkdir($outDir, 0777, true) && ! is_dir($outDir)) {
            throw new \RuntimeException("Cannot create output dir {$outDir}");
        }
        $plan = self::plan();
        $planHash = ExperimentRunner::planHash($plan);
        file_put_contents($outDir.DIRECTORY_SEPARATOR.'plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($outDir.DIRECTORY_SEPARATOR.'PLAN_SHA256.txt', $planHash."\n");

        $ivMs = 3600 * 1000;
        $startMs = strtotime($plan['window_start_utc']) * 1000;
        $endMs = strtotime($plan['window_end_utc']) * 1000;

        $windows = [];
        $excl = ['sealed' => 0, 'gap' => 0];
        $barsCount = [];
        foreach ($plan['symbols'] as $symbol) {
            $bars = $this->fetcher->fetch($symbol, $plan['timeframe'], $ivMs, $startMs, $endMs, ExperimentDatasetBuilder::SEAL_TS * 1000);
            $barsCount[$symbol] = count($bars);
            $r = ExperimentDatasetBuilder::windows($symbol, $bars, $ivMs, $plan['context_bars'], $plan['horizon_bars'], $plan['step_bars']);
            $w = $r['windows'];
            if ($maxWindowsPerSymbol !== null) {
                $w = array_slice($w, 0, $maxWindowsPerSymbol);
            }
            array_push($windows, ...$w);
            $excl['sealed'] += $r['excluded_sealed'];
            $excl['gap'] += $r['excluded_gap'];
        }

        $batch = $this->chronos->run(
            array_map(static fn ($w) => ['key' => $w['key'], 'closes' => $w['closes']], $windows),
            $plan['horizon_bars'],
            $outDir,
        );

        $samples = [];
        $unavailableC = 0;
        $diag = ['n_forecasts' => 0, 'cov_p10_p90_hits' => 0, 'mae_chronos_p50' => 0.0, 'mae_naive_last' => 0.0,
            'dir_hits' => 0, 'dir_n' => 0, 'dir_gross_sum' => 0.0];
        foreach ($windows as $w) {
            $samples[] = ExperimentDatasetBuilder::armA($w, $plan['horizon_bars']);
            $f = $batch['results'][$w['key']] ?? null;
            $c = ExperimentDatasetBuilder::armC($w, $f, $plan['threshold_fraction']);
            if ($c === null) {
                $unavailableC++;

                continue;
            }
            $samples[] = $c;
            $diag['n_forecasts']++;
            $diag['cov_p10_p90_hits'] += ($w['exit'] >= $f['p10'] && $w['exit'] <= $f['p90']) ? 1 : 0;
            $diag['mae_chronos_p50'] += abs($f['p50'] - $w['exit']) / $w['entry'];
            $diag['mae_naive_last'] += abs($w['entry'] - $w['exit']) / $w['entry'];
            if ($f['p50'] != $w['entry']) {
                $dir = $f['p50'] > $w['entry'] ? 1 : -1;
                $diag['dir_n']++;
                $ret = $dir * ($w['exit'] / $w['entry'] - 1.0);
                $diag['dir_gross_sum'] += $ret;
                $diag['dir_hits'] += $ret > 0 ? 1 : 0;
            }
        }

        $n = $diag['n_forecasts'];
        $descriptive = [
            'n_forecasts' => $n,
            'p10_p90_coverage (nominal 0.80)' => $n ? $diag['cov_p10_p90_hits'] / $n : null,
            'mase_like_chronos_over_naive (<1 better)' => ($n && $diag['mae_naive_last'] > 0) ? $diag['mae_chronos_p50'] / $diag['mae_naive_last'] : null,
            'unthresholded_direction_hit_rate' => $diag['dir_n'] ? $diag['dir_hits'] / $diag['dir_n'] : null,
            'unthresholded_direction_gross_mean' => $diag['dir_n'] ? $diag['dir_gross_sum'] / $diag['dir_n'] : null,
        ];

        $result = [
            'plan_sha256' => $planHash,
            'bars_fetched' => $barsCount,
            'windows' => count($windows),
            'excluded_sealed_windows' => $excl['sealed'],
            'excluded_gap_windows' => $excl['gap'],
            'chronos' => ['status' => $batch['status'], 'reason' => $batch['reason'], 'revision' => $batch['revision'], 'forecasts' => count($batch['results']), 'unavailable_c_samples' => $unavailableC],
            'descriptive_c_diagnostics' => $descriptive,
            'execution_authorized' => false,
        ];

        if ($batch['status'] !== 'OK') {
            $result['verdict'] = 'BLOCKED_MODEL_UNAVAILABLE';
        } else {
            $runner = new ExperimentRunner($plan['cost'], $plan['seed']);
            $result['experiment'] = [];
            foreach ($plan['cost_stress_multipliers'] as $m) {
                $result['experiment']['stress_'.$m] = $runner->run($samples, (float) $m);
            }
            $result['verdict'] = 'SEE_ARMS';
        }

        file_put_contents($outDir.DIRECTORY_SEPARATOR.'samples.json', json_encode($samples));
        file_put_contents($outDir.DIRECTORY_SEPARATOR.'result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $result;
    }
}
