<?php

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Domain\RatioBot\ForecastModelPromotionGate;
use App\Domain\Trading\ForecastMetricsService;
use App\Models\RatioBotForecastBacktest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeBacktestRow(string $botType, string $botInstanceId, int $horizon, string $bucket, string $period, int $sampleSize, ?\Illuminate\Support\Carbon $computedAt = null): RatioBotForecastBacktest
{
    return RatioBotForecastBacktest::create([
        'bot_type' => $botType,
        'bot_instance_id' => $botInstanceId,
        'horizon_seconds' => $horizon,
        'confidence_bucket' => $bucket,
        'evaluation_period' => $period,
        'sample_size' => $sampleSize,
        'hits' => (int) round($sampleSize * 0.6),
        'hit_rate_pct' => 60.0,
        'avg_confidence_pct' => 65.0,
        'computed_at' => $computedAt ?? now(),
    ]);
}

it('returns INSUFFICIENT_DATA when there is no training backtest data at all', function () {
    $result = app(ForecastModelPromotionGate::class)->evaluate('quick_trade', 'no-data-instance', 300);

    expect($result['status'])->toBe(ForecastModelPromotionGate::STATUS_INSUFFICIENT_DATA)
        ->and($result['reasons'])->toContain('no_training_backtest_data');
});

it('returns NOT_VALIDATED when training data exists but no holdout has ever been evaluated', function () {
    makeBacktestRow('quick_trade', 'training-only-instance', 300, '50-60', 'training', 50);

    $result = app(ForecastModelPromotionGate::class)->evaluate('quick_trade', 'training-only-instance', 300);

    expect($result['status'])->toBe(ForecastModelPromotionGate::STATUS_NOT_VALIDATED)
        ->and($result['reasons'])->toContain('holdout_not_evaluated');
});

it('returns NOT_VALIDATED when holdout exists but no bucket clears the calibration sample floor', function () {
    $sparse = ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE - 1;
    makeBacktestRow('quick_trade', 'sparse-instance', 300, '50-60', 'training', $sparse);
    makeBacktestRow('quick_trade', 'sparse-instance', 300, '50-60', 'holdout', $sparse);

    $result = app(ForecastModelPromotionGate::class)->evaluate('quick_trade', 'sparse-instance', 300);

    expect($result['status'])->toBe(ForecastModelPromotionGate::STATUS_NOT_VALIDATED)
        ->and($result['reasons'])->toContain('no_calibrated_confidence_buckets_with_real_volume');
});

it('returns NOT_VALIDATED when the backtest data is stale', function () {
    $ok = ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE + 10;
    makeBacktestRow('quick_trade', 'stale-instance', 300, '50-60', 'training', $ok);
    makeBacktestRow('quick_trade', 'stale-instance', 300, '50-60', 'holdout', $ok,
        now()->subDays(ForecastModelPromotionGate::MAX_CALIBRATION_AGE_DAYS + 5));

    $result = app(ForecastModelPromotionGate::class)->evaluate('quick_trade', 'stale-instance', 300);

    expect($result['status'])->toBe(ForecastModelPromotionGate::STATUS_NOT_VALIDATED)
        ->and($result['reasons'])->toContain('stale_backtest_data');
});

it('returns NOT_VALIDATED when calibration is fresh and sufficient but no real holdout P&L evidence is supplied', function () {
    $ok = ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE + 10;
    makeBacktestRow('quick_trade', 'no-pnl-instance', 300, '50-60', 'training', $ok);
    makeBacktestRow('quick_trade', 'no-pnl-instance', 300, '50-60', 'holdout', $ok);

    $result = app(ForecastModelPromotionGate::class)->evaluate('quick_trade', 'no-pnl-instance', 300, null);

    expect($result['status'])->toBe(ForecastModelPromotionGate::STATUS_NOT_VALIDATED)
        ->and($result['reasons'])->toContain('holdout_pnl_evidence_not_supplied');
});

it('returns VALIDATED when every gate passes', function () {
    $ok = ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE + 10;
    makeBacktestRow('quick_trade', 'validated-instance', 300, '50-60', 'training', $ok);
    makeBacktestRow('quick_trade', 'validated-instance', 300, '50-60', 'holdout', $ok);

    $pnlSummary = [
        'sample_size' => ForecastMetricsService::MIN_TRUSTED_SAMPLES,
        'net_pnl' => 150.0,
        'max_drawdown_pct' => 5.0,
    ];

    $result = app(ForecastModelPromotionGate::class)->evaluate('quick_trade', 'validated-instance', 300, $pnlSummary);

    expect($result['status'])->toBe(ForecastModelPromotionGate::STATUS_VALIDATED);
});

it('returns NOT_TRADEABLE when holdout net P&L is not positive despite sufficient, fresh, calibrated evidence', function () {
    $ok = ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE + 10;
    makeBacktestRow('quick_trade', 'losing-instance', 300, '50-60', 'training', $ok);
    makeBacktestRow('quick_trade', 'losing-instance', 300, '50-60', 'holdout', $ok);

    $pnlSummary = [
        'sample_size' => ForecastMetricsService::MIN_TRUSTED_SAMPLES,
        'net_pnl' => -25.0,
        'max_drawdown_pct' => 5.0,
    ];

    $result = app(ForecastModelPromotionGate::class)->evaluate('quick_trade', 'losing-instance', 300, $pnlSummary);

    expect($result['status'])->toBe(ForecastModelPromotionGate::STATUS_NOT_TRADEABLE)
        ->and($result['reasons'])->toContain('holdout_net_pnl_not_positive');
});

it('returns NOT_TRADEABLE when holdout drawdown breaches the documented threshold even with positive P&L', function () {
    $ok = ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE + 10;
    makeBacktestRow('quick_trade', 'drawdown-instance', 300, '50-60', 'training', $ok);
    makeBacktestRow('quick_trade', 'drawdown-instance', 300, '50-60', 'holdout', $ok);

    $pnlSummary = [
        'sample_size' => ForecastMetricsService::MIN_TRUSTED_SAMPLES,
        'net_pnl' => 50.0,
        'max_drawdown_pct' => ForecastModelPromotionGate::MAX_HOLDOUT_DRAWDOWN_PCT + 1.0,
    ];

    $result = app(ForecastModelPromotionGate::class)->evaluate('quick_trade', 'drawdown-instance', 300, $pnlSummary);

    expect($result['status'])->toBe(ForecastModelPromotionGate::STATUS_NOT_TRADEABLE)
        ->and($result['reasons'])->toContain('holdout_drawdown_exceeds_threshold');
});

it('never writes to the database -- it is a read-only evaluator', function () {
    $ok = ForecastHorizonBacktestService::MIN_BUCKET_SAMPLE_SIZE + 10;
    makeBacktestRow('quick_trade', 'readonly-instance', 300, '50-60', 'training', $ok);
    makeBacktestRow('quick_trade', 'readonly-instance', 300, '50-60', 'holdout', $ok);

    $countBefore = RatioBotForecastBacktest::count();
    app(ForecastModelPromotionGate::class)->evaluate('quick_trade', 'readonly-instance', 300, [
        'sample_size' => ForecastMetricsService::MIN_TRUSTED_SAMPLES, 'net_pnl' => 10.0, 'max_drawdown_pct' => 1.0,
    ]);

    expect(RatioBotForecastBacktest::count())->toBe($countBefore);
});
