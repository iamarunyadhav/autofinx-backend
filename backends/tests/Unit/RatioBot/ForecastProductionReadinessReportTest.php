<?php

use App\Domain\RatioBot\ForecastModelPromotionGate;
use App\Domain\RatioBot\ForecastProductionReadinessReport;
use App\Models\RatioBotForecastBacktest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedReadinessScope(string $botInstanceId, int $horizon = 300): void
{
    foreach (['training', 'holdout'] as $period) {
        RatioBotForecastBacktest::create([
            'bot_type' => 'quick_trade',
            'bot_instance_id' => $botInstanceId,
            'horizon_seconds' => $horizon,
            'confidence_bucket' => '50-60',
            'evaluation_period' => $period,
            'sample_size' => 40,
            'hits' => 24,
            'hit_rate_pct' => 60.0,
            'avg_confidence_pct' => 55.0,
            'computed_at' => now(),
        ]);
    }
}

it('never writes to the database -- it is a read-only report', function () {
    seedReadinessScope('ro-report-instance');

    $countBefore = RatioBotForecastBacktest::count();
    app(ForecastProductionReadinessReport::class)->generate('quick_trade', 'ro-report-instance', 300, [
        'sample_size' => 30, 'net_pnl' => 10.0, 'max_drawdown_pct' => 2.0,
        'direction_accuracy_pct' => 55.0, 'price_mae' => 0.01, 'range_hit_rate_pct' => 50.0,
    ]);

    expect(RatioBotForecastBacktest::count())->toBe($countBefore);
});

it('produces a full report shape with real numbers and detects genuine limitations', function () {
    seedReadinessScope('readiness-instance');

    $holdoutPnlSummary = [
        'sample_size' => 30,
        'net_pnl' => 150.0,
        'max_drawdown_amount' => 10.0,
        'max_drawdown_pct' => 5.0,
        'direction_accuracy_pct' => 62.0,
        'price_mae' => 0.015,
        'range_hit_rate_pct' => 58.0,
    ];

    $report = app(ForecastProductionReadinessReport::class)->generate('quick_trade', 'readiness-instance', 300, $holdoutPnlSummary);

    expect($report['data_source']['training_sample_size'])->toBe(40)
        ->and($report['data_source']['holdout_sample_size'])->toBe(40)
        ->and($report['data_source']['training_period']['computed_at_earliest'])->not->toBeNull()
        ->and($report['data_source']['holdout_period']['computed_at_earliest'])->not->toBeNull()
        ->and($report['performance']['direction_accuracy_pct'])->toBe(62.0)
        ->and($report['performance']['price_mae'])->toBe(0.015)
        ->and($report['performance']['range_hit_rate_pct'])->toBe(58.0)
        ->and($report['performance']['net_pnl'])->toBe(150.0)
        ->and($report['performance']['max_drawdown_pct'])->toBe(5.0)
        ->and($report['calibration']['brier_score'])->not->toBeNull()
        ->and($report['promotion_gate']['status'])->toBe(ForecastModelPromotionGate::STATUS_VALIDATED);

    // Real, detected limitations -- never a static hardcoded list.
    expect($report['known_limitations'])->toContain(
        'Brier score is a bucket-weighted approximation (per-bucket average confidence '
        .'used as the predicted probability for every prediction in that bucket), not a true '
        .'per-prediction Brier score -- RatioBotForecastBacktest stores bucket aggregates only.',
    );
    $uncalibratedLimitation = collect($report['known_limitations'])->first(fn (string $l) => str_contains($l, 'have not reached the sample-size floor'));
    expect($uncalibratedLimitation)->not->toBeNull()
        ->and($uncalibratedLimitation)->toContain('60-70');

    // Gate is VALIDATED and P&L sample clears the floor, so those specific
    // limitation strings must NOT appear.
    $gateLimitation = collect($report['known_limitations'])->first(fn (string $l) => str_starts_with($l, 'Promotion gate status is'));
    expect($gateLimitation)->toBeNull();
});

it('reports insufficient-data limitations honestly when there is no backtest data and no P&L evidence at all', function () {
    $report = app(ForecastProductionReadinessReport::class)->generate('quick_trade', 'empty-instance', 300, null);

    expect($report['data_source']['training_sample_size'])->toBe(0)
        ->and($report['data_source']['holdout_sample_size'])->toBe(0)
        ->and($report['calibration']['brier_score'])->toBeNull()
        ->and($report['promotion_gate']['status'])->toBe(ForecastModelPromotionGate::STATUS_INSUFFICIENT_DATA)
        ->and($report['performance']['net_pnl'])->toBeNull();

    expect($report['known_limitations'])->toContain('No training-period backtest data exists for this scope yet.')
        ->and($report['known_limitations'])->toContain('No holdout-period backtest data exists for this scope yet -- this model has never been evaluated out-of-sample.')
        ->and($report['known_limitations'])->toContain('No real, settled-trade holdout P&L evidence was supplied -- direction accuracy, '
            .'MAE, range hit rate, net P&L, and max drawdown below are all unavailable.');

    $gateLimitation = collect($report['known_limitations'])->first(fn (string $l) => str_starts_with($l, 'Promotion gate status is'));
    expect($gateLimitation)->not->toBeNull()
        ->and($gateLimitation)->toContain(ForecastModelPromotionGate::STATUS_INSUFFICIENT_DATA);
});
