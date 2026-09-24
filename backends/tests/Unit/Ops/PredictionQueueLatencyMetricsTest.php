<?php

use App\Domain\Ops\PredictionQueueLatencyMetrics;
use App\Domain\Trading\QuickTradeForecastCalculator;
use App\Domain\Trading\TradeForecastService;
use App\Jobs\CalculateTradeForecast;
use Illuminate\Support\Facades\Cache;

/**
 * Gap-checklist N2 -- p50/p95 queue_wait_ms / compute_ms for the
 * `predictions` queue, plus the count of jobs dropped by the 20s rule.
 * Same array-backed `redis` store trick AsyncTradeForecastTest uses.
 */
beforeEach(function () {
    config(['cache.stores.redis' => ['driver' => 'array', 'serialize' => false]]);
    Cache::forgetDriver('redis');
});

test('summary reports nearest-rank p50/p95 and drop counts from fixture samples', function () {
    $metrics = app(PredictionQueueLatencyMetrics::class);
    $now = time();

    // 20 published samples: queue wait 100..2000 step 100, compute 10..200 step 10.
    for ($i = 1; $i <= 20; $i++) {
        $metrics->record(PredictionQueueLatencyMetrics::OUTCOME_PUBLISHED, $i * 100, $i * 10, 'forecast', $now);
    }
    // 2 dropped before compute (long queue wait, no compute time).
    $metrics->record(PredictionQueueLatencyMetrics::OUTCOME_DROPPED_BEFORE_COMPUTE, 21000, null, 'forecast', $now);
    $metrics->record(PredictionQueueLatencyMetrics::OUTCOME_DROPPED_BEFORE_COMPUTE, 25000, null, 'forecast', $now);
    // 1 computed but finished outside the window.
    $metrics->record(PredictionQueueLatencyMetrics::OUTCOME_DROPPED_AFTER_COMPUTE, 15000, 6000, 'forecast', $now);

    $summary = $metrics->summary($now);

    expect($summary['available'])->toBeTrue()
        ->and($summary['queue'])->toBe('predictions')
        ->and($summary['samples'])->toBe(23)
        ->and($summary['published'])->toBe(20)
        ->and($summary['dropped_total'])->toBe(3)
        ->and($summary['dropped_expired_before_compute'])->toBe(2)
        ->and($summary['dropped_expired_after_compute'])->toBe(1)
        ->and($summary['drop_rate_pct'])->toBe(13.04);

    // queue_wait over all 23 samples: 100..2000, 15000, 21000, 25000.
    // nearest rank p50 = ceil(11.5) = 12th = 1200; p95 = ceil(21.85) = 22nd = 21000.
    expect($summary['queue_wait_ms']['p50'])->toBe(1200)
        ->and($summary['queue_wait_ms']['p95'])->toBe(21000)
        ->and($summary['queue_wait_ms']['max'])->toBe(25000)
        ->and($summary['queue_wait_ms']['n'])->toBe(23);

    // compute over the 21 jobs that computed: 10..200, 6000.
    // p50 = ceil(10.5) = 11th = 110; p95 = ceil(19.95) = 20th = 200.
    expect($summary['compute_ms']['p50'])->toBe(110)
        ->and($summary['compute_ms']['p95'])->toBe(200)
        ->and($summary['compute_ms']['max'])->toBe(6000)
        ->and($summary['compute_ms']['n'])->toBe(21);
});

test('empty window reports null percentiles, never a fabricated zero', function () {
    $summary = app(PredictionQueueLatencyMetrics::class)->summary();

    expect($summary['available'])->toBeTrue()
        ->and($summary['samples'])->toBe(0)
        ->and($summary['dropped_total'])->toBe(0)
        ->and($summary['drop_rate_pct'])->toBeNull()
        ->and($summary['queue_wait_ms']['p50'])->toBeNull()
        ->and($summary['queue_wait_ms']['p95'])->toBeNull()
        ->and($summary['compute_ms']['p50'])->toBeNull();
});

test('samples older than the reporting window are excluded', function () {
    $metrics = app(PredictionQueueLatencyMetrics::class);
    $now = time();
    $metrics->record(PredictionQueueLatencyMetrics::OUTCOME_PUBLISHED, 9999, 9999, 'forecast', $now - PredictionQueueLatencyMetrics::WINDOW_SECONDS - 5);
    $metrics->record(PredictionQueueLatencyMetrics::OUTCOME_PUBLISHED, 100, 10, 'forecast', $now);

    $summary = $metrics->summary($now);

    expect($summary['samples'])->toBe(1)
        ->and($summary['queue_wait_ms']['max'])->toBe(100);
});

test('the forecast worker records a dropped-before-compute sample for an expired input', function () {
    $cache = Cache::store('redis');
    $lock = $cache->lock('n2-expired:pending', 45);
    $lock->get();
    $calculator = Mockery::mock(QuickTradeForecastCalculator::class);
    $calculator->shouldNotReceive('forecast');

    (new CalculateTradeForecast('n2-expired', 'n2-expired:pending', $lock->owner(), 'forecast', [], time() - 21))
        ->handle($calculator, app(TradeForecastService::class));

    $summary = app(PredictionQueueLatencyMetrics::class)->summary();
    expect($summary['dropped_expired_before_compute'])->toBe(1)
        ->and($summary['published'])->toBe(0)
        ->and($summary['queue_wait_ms']['max'])->toBeGreaterThanOrEqual(21000);
});

test('the forecast worker records a published sample with the same latency it attaches to the result', function () {
    $cache = Cache::store('redis');
    $lock = $cache->lock('n2-ok:pending', 45);
    $lock->get();
    $calculator = Mockery::mock(QuickTradeForecastCalculator::class);
    $calculator->shouldReceive('forecast')->once()->andReturn(['forecast' => ['decision_trend' => 'WAIT']]);
    $data = ['symbol' => 'BTCUSDT', 'timeframe' => '5m', 'expiry_seconds' => 3600];

    (new CalculateTradeForecast('n2-ok', 'n2-ok:pending', $lock->owner(), 'forecast', $data, time()))
        ->handle($calculator, app(TradeForecastService::class));

    $published = $cache->get('n2-ok');
    $summary = app(PredictionQueueLatencyMetrics::class)->summary();

    expect($summary['published'])->toBe(1)
        ->and($summary['dropped_total'])->toBe(0)
        ->and($summary['queue_wait_ms']['p50'])->toBe($published['calculation']['queue_wait_ms'])
        ->and($summary['compute_ms']['p50'])->toBe($published['calculation']['compute_ms']);
});

test('a metrics store failure never throws into the caller', function () {
    config(['cache.stores.redis' => ['driver' => 'this-driver-does-not-exist']]);
    Cache::forgetDriver('redis');
    $metrics = app(PredictionQueueLatencyMetrics::class);

    $metrics->record(PredictionQueueLatencyMetrics::OUTCOME_PUBLISHED, 10, 10);

    expect($metrics->summary())->toMatchArray(['available' => false, 'reason' => 'metrics_store_unavailable']);
});
