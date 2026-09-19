<?php

use App\Domain\RatioBot\ForecastModelComparisonService;
use App\Domain\RatioBot\ForecastModelPromotionGate;
use App\Models\RatioBotForecastBacktest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedComparisonScope(string $botInstanceId, int $horizon = 300): void
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

it('never writes to the database -- it is a read-only comparison', function () {
    seedComparisonScope('ro-a');
    seedComparisonScope('ro-b');

    $countBefore = RatioBotForecastBacktest::count();
    app(ForecastModelComparisonService::class)->compare(
        ['bot_type' => 'quick_trade', 'bot_instance_id' => 'ro-a', 'horizon_seconds' => 300, 'label' => 'A'],
        ['bot_type' => 'quick_trade', 'bot_instance_id' => 'ro-b', 'horizon_seconds' => 300, 'label' => 'B'],
        ['sample_size' => 30, 'net_pnl' => 10.0, 'max_drawdown_pct' => 2.0, 'direction_accuracy_pct' => 55.0],
        ['sample_size' => 30, 'net_pnl' => 20.0, 'max_drawdown_pct' => 2.0, 'direction_accuracy_pct' => 60.0],
    );

    expect(RatioBotForecastBacktest::count())->toBe($countBefore);
});

it('recommends the model with higher holdout net P&L when both are validated with comparable drawdown', function () {
    seedComparisonScope('model-a');
    seedComparisonScope('model-b');

    $result = app(ForecastModelComparisonService::class)->compare(
        ['bot_type' => 'quick_trade', 'bot_instance_id' => 'model-a', 'horizon_seconds' => 300, 'label' => 'Model A'],
        ['bot_type' => 'quick_trade', 'bot_instance_id' => 'model-b', 'horizon_seconds' => 300, 'label' => 'Model B'],
        ['sample_size' => 30, 'net_pnl' => 100.0, 'max_drawdown_pct' => 5.0, 'direction_accuracy_pct' => 55.0],
        ['sample_size' => 30, 'net_pnl' => 300.0, 'max_drawdown_pct' => 5.0, 'direction_accuracy_pct' => 62.0],
    );

    expect($result['model_a']['gate_status'])->toBe(ForecastModelPromotionGate::STATUS_VALIDATED)
        ->and($result['model_b']['gate_status'])->toBe(ForecastModelPromotionGate::STATUS_VALIDATED)
        ->and($result['deltas']['net_pnl'])->toBe(200.0)
        ->and($result['deltas']['max_drawdown_pct'])->toBe(0.0)
        ->and($result['recommendation'])->toContain('Model B')
        ->and($result['recommendation'])->toContain('300.00');
});

it('recommends the only validated model and names the other model\'s real gate status when they differ', function () {
    seedComparisonScope('validated-model');
    // 'unvalidated-model' has no backtest rows at all.

    $result = app(ForecastModelComparisonService::class)->compare(
        ['bot_type' => 'quick_trade', 'bot_instance_id' => 'validated-model', 'horizon_seconds' => 300, 'label' => 'Validated'],
        ['bot_type' => 'quick_trade', 'bot_instance_id' => 'unvalidated-model', 'horizon_seconds' => 300, 'label' => 'Unvalidated'],
        ['sample_size' => 30, 'net_pnl' => 50.0, 'max_drawdown_pct' => 2.0, 'direction_accuracy_pct' => 55.0],
        null,
    );

    expect($result['model_a']['gate_status'])->toBe(ForecastModelPromotionGate::STATUS_VALIDATED)
        ->and($result['model_b']['gate_status'])->toBe(ForecastModelPromotionGate::STATUS_INSUFFICIENT_DATA)
        ->and($result['recommendation'])->toContain('Validated')
        ->and($result['recommendation'])->toContain('INSUFFICIENT_DATA');
});

it('recommends against a switch when neither model clears the promotion gate', function () {
    $result = app(ForecastModelComparisonService::class)->compare(
        ['bot_type' => 'quick_trade', 'bot_instance_id' => 'no-data-a', 'horizon_seconds' => 300, 'label' => 'A'],
        ['bot_type' => 'quick_trade', 'bot_instance_id' => 'no-data-b', 'horizon_seconds' => 300, 'label' => 'B'],
        null,
        null,
    );

    expect($result['recommendation'])->toContain('Neither model clears the promotion gate');
});
