<?php

namespace Tests\Unit\MarketOpportunityBrain\Validation;

use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicHistoryFetcher;
use App\Domain\MarketOpportunityBrain\Adapters\BinancePublicRestClient;
use App\Domain\MarketOpportunityBrain\Forecast\ChronosBatchRunner;
use App\Domain\MarketOpportunityBrain\Validation\ExperimentDatasetBuilder as B;
use App\Domain\MarketOpportunityBrain\Validation\ExperimentRunner;
use App\Domain\MarketOpportunityBrain\Validation\ExperimentV1Orchestrator;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExperimentDatasetBuilderTest extends TestCase
{
    private const IV = 3600000;

    private function bars(int $n, int $startMs, float $start = 100.0, float $drift = 0.001): array
    {
        $rows = [];
        $p = $start;
        for ($i = 0; $i < $n; $i++) {
            $open = $startMs + $i * self::IV;
            $p *= 1 + $drift;
            $rows[] = [$open, (string) $p, (string) $p, (string) $p, (string) $p, '1', $open + self::IV - 1];
        }

        return $rows;
    }

    /** @test */
    public function windows_are_causal_and_non_overlapping(): void
    {
        $r = B::windows('X', $this->bars(40, 1735689600000), self::IV, 10, 4, 4);
        $this->assertNotEmpty($r['windows']);
        $prev = null;
        foreach ($r['windows'] as $w) {
            $this->assertCount(10, $w['closes']);
            $this->assertSame(4 * 3600, $w['label_available_at'] - $w['as_of']);
            $this->assertGreaterThan($w['as_of'], $w['label_available_at']);
            if ($prev) {
                $this->assertGreaterThanOrEqual($prev['label_available_at'], $w['as_of']);
            }
            $prev = $w;
        }
    }

    /** @test */
    public function sealed_holdout_windows_are_excluded_never_emitted(): void
    {
        // bars straddling the seal (2026-01-04T00:00Z = 1767484800)
        $start = (B::SEAL_TS - 20 * 3600) * 1000;
        $r = B::windows('X', $this->bars(60, $start), self::IV, 10, 4, 4);
        $this->assertGreaterThan(0, $r['excluded_sealed']);
        foreach ($r['windows'] as $w) {
            $this->assertLessThanOrEqual(B::SEAL_TS, $w['label_available_at']);
        }
    }

    /** @test */
    public function gap_windows_are_skipped(): void
    {
        $bars = $this->bars(40, 1735689600000);
        array_splice($bars, 15, 1); // remove one bar -> gap
        $r = B::windows('X', $bars, self::IV, 10, 4, 4);
        $this->assertGreaterThan(0, $r['excluded_gap']);
    }

    /** @test */
    public function arm_a_and_c_signs_and_wait_threshold(): void
    {
        $w = ['key' => 'k', 'symbol' => 'X', 'as_of' => 10, 'label_available_at' => 20, 'closes' => [1, 2, 3, 4, 5, 6], 'entry' => 100.0, 'exit' => 101.0];
        $a = B::armA($w, 4);
        $this->assertSame('LONG', $a['side']);
        $this->assertEqualsWithDelta(0.01, $a['gross_return'], 1e-12);
        $this->assertFalse($a['holdout']);
        $this->assertSame('SHORT', B::armC($w, ['p10' => 90, 'p50' => 99.0, 'p90' => 105], 0.002)['side']);
        $this->assertEqualsWithDelta(-0.01, B::armC($w, ['p10' => 90, 'p50' => 99.0, 'p90' => 105], 0.002)['gross_return'], 1e-12);
        $this->assertSame('WAIT', B::armC($w, ['p10' => 90, 'p50' => 100.1, 'p90' => 105], 0.002)['side']);
        $this->assertNull(B::armC($w, null, 0.002));
    }

    /** @test */
    public function samples_pass_the_experiment_runner_causal_guards(): void
    {
        $r = B::windows('X', $this->bars(200, 1735689600000, 100, 0.0005), self::IV, 20, 4, 4);
        $samples = [];
        foreach ($r['windows'] as $w) {
            $samples[] = B::armA($w, 4);
        }
        $out = (new ExperimentRunner(['fee_bps_per_side' => 5, 'slippage_bps_per_side' => 1, 'funding_bps_per_trade' => 0]))->run($samples);
        $this->assertFalse($out['execution_authorized']);
        $this->assertSame(count($samples), $out['arms']['A']['samples']);
    }

    /** @test */
    public function fetcher_refuses_windows_that_reach_the_seal_or_future(): void
    {
        config(['market_opportunity_brain.public_data_enabled' => true, 'market_opportunity_brain.max_api_weight_per_minute' => 1200, 'market_opportunity_brain.max_discovery_symbols' => 5]);
        Http::fake();
        $f = new BinancePublicHistoryFetcher(new BinancePublicRestClient());
        $this->expectException(\InvalidArgumentException::class);
        try {
            $f->fetch('BTCUSDT', '1h', self::IV, 1735689600000, B::SEAL_TS * 1000, B::SEAL_TS * 1000);
        } finally {
            Http::assertNothingSent();
        }
    }

    /** @test */
    public function fetcher_pages_and_dedupes_public_klines(): void
    {
        config(['market_opportunity_brain.public_data_enabled' => true, 'market_opportunity_brain.max_api_weight_per_minute' => 1200, 'market_opportunity_brain.max_discovery_symbols' => 5, 'cache.default' => 'array']);
        $all = $this->bars(1600, 1735689600000);
        Http::fake(function ($req) use ($all) {
            parse_str((string) parse_url($req->url(), PHP_URL_QUERY), $q);
            $rows = array_values(array_filter($all, fn ($r) => $r[0] >= (int) $q['startTime'] && $r[0] <= (int) $q['endTime']));

            return Http::response(array_slice($rows, 0, (int) $q['limit']));
        });
        $end = 1735689600000 + 1600 * self::IV - 1;
        $f = new BinancePublicHistoryFetcher(new BinancePublicRestClient());
        $got = $f->fetch('BTCUSDT', '1h', self::IV, 1735689600000, $end, B::SEAL_TS * 1000);
        $this->assertCount(1600, $got);
    }

    /** @test */
    public function chronos_runner_reports_unavailable_without_python_bin_and_orchestrator_blocks(): void
    {
        config(['market_opportunity_brain.python_bin' => null]);
        $r = (new ChronosBatchRunner())->run([['key' => 'k', 'closes' => [1.0, 2.0]]], 4, sys_get_temp_dir().DIRECTORY_SEPARATOR.'mob_t');
        $this->assertSame('MODEL_UNAVAILABLE', $r['status']);
        $this->assertSame([], $r['results']);
        $plan = ExperimentV1Orchestrator::plan();
        $this->assertFalse($plan['execution_authorized']);
        $this->assertSame(64, strlen(ExperimentRunner::planHash($plan)));
    }
}
