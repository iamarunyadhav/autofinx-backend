<?php

use App\Domain\RatioBot\ForecastCalibrationQualityService;
use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Models\ForecastBacktestPrediction;
use App\Models\RatioBotForecastBacktest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function seedCalibrationRow(string $botType, string $botInstanceId, int $horizon, string $bucket, string $period, int $sampleSize, int $hits, float $hitRatePct, float $avgConfidencePct): RatioBotForecastBacktest
{
    return RatioBotForecastBacktest::create([
        'bot_type' => $botType,
        'bot_instance_id' => $botInstanceId,
        'horizon_seconds' => $horizon,
        'confidence_bucket' => $bucket,
        'evaluation_period' => $period,
        'sample_size' => $sampleSize,
        'hits' => $hits,
        'hit_rate_pct' => $hitRatePct,
        'avg_confidence_pct' => $avgConfidencePct,
        'computed_at' => now(),
    ]);
}

/**
 * @param  array<int, array{confidence_pct: float, predicted_hit: bool}>  $predictions
 */
function seedPredictions(string $botType, string $botInstanceId, int $horizon, string $period, array $predictions): void
{
    $now = now();
    foreach ($predictions as $i => $prediction) {
        ForecastBacktestPrediction::create([
            'bot_type' => $botType,
            'bot_instance_id' => $botInstanceId,
            'horizon_seconds' => $horizon,
            'evaluation_period' => $period,
            'confidence_pct' => $prediction['confidence_pct'],
            'predicted_hit' => $prediction['predicted_hit'],
            'candle_ts' => $now->copy()->subMinutes(count($predictions) - $i),
            'backtest_run_id' => (string) Str::uuid(),
        ]);
    }
}

it('never writes to the database -- it is a read-only analytics service', function () {
    seedCalibrationRow('quick_trade', 'ro-instance', 300, '80-90', 'holdout', 40, 25, 62.5, 85.0);

    $countBefore = RatioBotForecastBacktest::count();
    app(ForecastCalibrationQualityService::class)->evaluate('quick_trade', 'ro-instance', 300, 'holdout');

    expect(RatioBotForecastBacktest::count())->toBe($countBefore);
});

it('computes the reliability curve, a hand-computable Brier score, and flags overconfidence from known bucket data', function () {
    // 80-90 bucket: 40 samples (clears the 30-sample floor), 25 hits,
    // hit_rate_pct = 62.5, avg_confidence_pct = 85 (midpoint of 80-90 is
    // also 85 here, kept equal for a clean hand-computation).
    seedCalibrationRow('quick_trade', 'calib-instance', 300, '80-90', 'holdout', 40, 25, 62.5, 85.0);

    $result = app(ForecastCalibrationQualityService::class)->evaluate('quick_trade', 'calib-instance', 300, 'holdout');

    // Reliability curve: one entry per default bucket, in order.
    expect($result['reliability_curve'])->toHaveCount(5);
    $entry = collect($result['reliability_curve'])->firstWhere('confidence_bucket', '80-90');
    expect($entry['confidence_midpoint'])->toBe(85.0)
        ->and($entry['sample_size'])->toBe(40)
        ->and($entry['calibrated'])->toBeTrue()
        ->and($entry['actual_hit_rate_pct'])->toBe(62.5);

    $untouched = collect($result['reliability_curve'])->firstWhere('confidence_bucket', '50-60');
    expect($untouched['sample_size'])->toBe(0)
        ->and($untouched['calibrated'])->toBeFalse()
        ->and($untouched['actual_hit_rate_pct'])->toBeNull();

    // Brier score (bucket-weighted approximation): p = 0.85, 25 hits score
    // (1-0.85)^2 = 0.0225 each, 15 misses score (0.85)^2 = 0.7225 each.
    // sum = 25*0.0225 + 15*0.7225 = 0.5625 + 10.8375 = 11.4; /40 = 0.285.
    expect($result['brier_score'])->toBe(0.285)
        ->and($result['brier_score_sample_size'])->toBe(40)
        ->and($result['brier_score_is_approximation'])->toBeTrue();

    // Overconfidence: midpoint 85 - actual 62.5 = 22.5pp gap > 10pp threshold.
    expect($result['overconfident_buckets'])->toHaveCount(1);
    expect($result['overconfident_buckets'][0]['confidence_bucket'])->toBe('80-90')
        ->and($result['overconfident_buckets'][0]['gap_percentage_points'])->toBe(22.5);
});

it('reports Brier score as null when the combined calibrated sample is below the trusted floor', function () {
    seedCalibrationRow('quick_trade', 'sparse-calib-instance', 300, '50-60', 'holdout', 10, 6, 60.0, 55.0);

    $result = app(ForecastCalibrationQualityService::class)->evaluate('quick_trade', 'sparse-calib-instance', 300, 'holdout');

    expect($result['brier_score'])->toBeNull()
        ->and($result['brier_score_sample_size'])->toBe(10);
});

it('does not flag overconfidence for an uncalibrated bucket even with a large apparent gap', function () {
    // Only 5 samples -- below MIN_BUCKET_SAMPLE_SIZE (30) -- even though the
    // apparent gap (85 - 20 = 65pp) would otherwise trip the warning.
    seedCalibrationRow('quick_trade', 'noisy-instance', 300, '80-90', 'holdout', 5, 1, 20.0, 85.0);

    $result = app(ForecastCalibrationQualityService::class)->evaluate('quick_trade', 'noisy-instance', 300, 'holdout');

    expect($result['overconfident_buckets'])->toBe([]);
});

it('exposes the bucket midpoint helper matching documented bucket boundaries', function () {
    expect(ForecastCalibrationQualityService::bucketMidpoint('50-60'))->toBe(55.0)
        ->and(ForecastCalibrationQualityService::bucketMidpoint('90-100'))->toBe(95.0);
});

// === Exact-Brier-score tests (additive, exact-Brier-score task) ===

it('reports exact_available false and a null exact Brier score when no per-prediction rows exist for the scope', function () {
    seedCalibrationRow('quick_trade', 'no-exact-instance', 300, '80-90', 'holdout', 40, 25, 62.5, 85.0);

    $result = app(ForecastCalibrationQualityService::class)->evaluate('quick_trade', 'no-exact-instance', 300, 'holdout');

    expect($result['exact_available'])->toBeFalse()
        ->and($result['brier_score_exact'])->toBeNull()
        ->and($result['brier_score_exact_sample_size'])->toBe(0)
        // The pre-existing approximation must still be present and unaffected.
        ->and($result['brier_score'])->toBe(0.285)
        ->and($result['brier_score_is_approximation'])->toBeTrue();

    foreach ($result['reliability_curve'] as $entry) {
        expect($entry['exact_available'])->toBeFalse()
            ->and($entry['exact_sample_size'])->toBe(0)
            ->and($entry['exact_hit_rate_pct'])->toBeNull();
    }
});

/**
 * The core proof that the approximation's documented limitation is real:
 * the SAME bucket-level aggregate (sample_size=30, hits=15,
 * avg_confidence_pct=85 -- feeding the approximation's shared p_b=0.85)
 * is backed by 30 REAL per-prediction rows whose confidence actually
 * VARIES within the bucket (15 at 81%, 15 at 89% -- both still inside the
 * 80-90 bucket, averaging to 85 exactly, so the approximation and the
 * exact computation start from an identical bucket aggregate).
 *
 * Approximation (bucket-weighted, single shared p_b = 0.85):
 *   sumSq = hits*(1-p)^2 + misses*p^2 = 15*(0.15)^2 + 15*(0.85)^2
 *         = 15*0.0225 + 15*0.7225 = 0.3375 + 10.8375 = 11.175
 *   brier_score_approx = 11.175 / 30 = 0.3725
 *
 * Exact (true per-prediction confidence):
 *   15 predictions at p=0.81, hit (o=1): each (0.81-1)^2 = 0.0361
 *   15 predictions at p=0.89, miss (o=0): each (0.89-0)^2 = 0.7921
 *   sumSq = 15*0.0361 + 15*0.7921 = 0.5415 + 11.8815 = 12.423
 *   brier_score_exact = 12.423 / 30 = 0.4141
 *
 * 0.3725 != 0.4141 -- proves the approximation's documented "single p_b for
 * every prediction in the bucket" assumption measurably understates the
 * true Brier score here (real per-prediction spread within a bucket is not
 * captured by the bucket's own mean confidence).
 */
it('produces a measurably different exact Brier score than the approximation when confidence varies within a bucket', function () {
    seedCalibrationRow('quick_trade', 'varying-instance', 300, '80-90', 'holdout', 30, 15, 50.0, 85.0);

    $predictions = [];
    for ($i = 0; $i < 15; $i++) {
        $predictions[] = ['confidence_pct' => 81.0, 'predicted_hit' => true];
    }
    for ($i = 0; $i < 15; $i++) {
        $predictions[] = ['confidence_pct' => 89.0, 'predicted_hit' => false];
    }
    seedPredictions('quick_trade', 'varying-instance', 300, 'holdout', $predictions);

    $result = app(ForecastCalibrationQualityService::class)->evaluate('quick_trade', 'varying-instance', 300, 'holdout');

    expect($result['exact_available'])->toBeTrue()
        ->and($result['brier_score_exact_sample_size'])->toBe(30)
        ->and($result['brier_score'])->toBe(0.3725)
        ->and($result['brier_score_exact'])->toBe(0.4141)
        ->and($result['brier_score_exact'])->not->toBe($result['brier_score']);

    $entry = collect($result['reliability_curve'])->firstWhere('confidence_bucket', '80-90');
    expect($entry['exact_available'])->toBeTrue()
        ->and($entry['exact_sample_size'])->toBe(30)
        ->and($entry['exact_hit_rate_pct'])->toBe(50.0);
});

/**
 * Contrast case: when every prediction inside the bucket genuinely shares
 * the SAME confidence value (85 exactly, matching avg_confidence_pct), the
 * approximation's "one shared p_b" assumption is no longer an
 * approximation at all -- it is exactly what happened. Exact and approx
 * Brier scores must be identical here, proving the divergence in the test
 * above comes specifically from within-bucket confidence variance, not
 * from some other unrelated bug in either formula.
 */
it('produces an identical exact and approximate Brier score when confidence is uniform within the bucket', function () {
    seedCalibrationRow('quick_trade', 'uniform-instance', 300, '80-90', 'holdout', 30, 15, 50.0, 85.0);

    $predictions = [];
    for ($i = 0; $i < 15; $i++) {
        $predictions[] = ['confidence_pct' => 85.0, 'predicted_hit' => true];
    }
    for ($i = 0; $i < 15; $i++) {
        $predictions[] = ['confidence_pct' => 85.0, 'predicted_hit' => false];
    }
    seedPredictions('quick_trade', 'uniform-instance', 300, 'holdout', $predictions);

    $result = app(ForecastCalibrationQualityService::class)->evaluate('quick_trade', 'uniform-instance', 300, 'holdout');

    expect($result['exact_available'])->toBeTrue()
        ->and($result['brier_score'])->toBe(0.3725)
        ->and($result['brier_score_exact'])->toBe(0.3725)
        ->and($result['brier_score_exact'])->toBe($result['brier_score']);
});

it('gates the exact Brier score behind the same trusted-sample floor as the approximation, while still reporting exact_available separately', function () {
    seedCalibrationRow('quick_trade', 'sparse-exact-instance', 300, '50-60', 'holdout', 10, 6, 60.0, 55.0);
    seedPredictions('quick_trade', 'sparse-exact-instance', 300, 'holdout', array_merge(
        array_fill(0, 6, ['confidence_pct' => 55.0, 'predicted_hit' => true]),
        array_fill(0, 4, ['confidence_pct' => 55.0, 'predicted_hit' => false]),
    ));

    $result = app(ForecastCalibrationQualityService::class)->evaluate('quick_trade', 'sparse-exact-instance', 300, 'holdout');

    // Per-prediction data DOES exist (10 rows) -- exact_available is
    // honestly true -- but 10 < MIN_TRUSTED_SAMPLES (30), so the exact
    // Brier score itself is still withheld as untrustworthy, exactly like
    // the approximation already is for this same scope.
    expect($result['exact_available'])->toBeTrue()
        ->and($result['brier_score_exact_sample_size'])->toBe(10)
        ->and($result['brier_score_exact'])->toBeNull()
        ->and($result['brier_score'])->toBeNull();
});
