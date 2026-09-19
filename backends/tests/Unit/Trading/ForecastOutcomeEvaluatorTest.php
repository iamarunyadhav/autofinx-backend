<?php

use App\Domain\Trading\ForecastOutcomeEvaluator;

it('evaluates the first valid quote at or after the expiry target', function () {
    $target = \Carbon\CarbonImmutable::parse('2026-01-01T00:05:00Z');
    $result = app(ForecastOutcomeEvaluator::class)->evaluate([
        'available' => true,
        'generated_at' => $target->subMinutes(5)->toIso8601String(),
        'horizon_seconds' => 300,
        'reference_price' => 100,
        'predicted_expiry_price' => 101,
        'predicted_low' => 100.5,
        'predicted_high' => 101.5,
    ], [[
        'time_ms' => $target->getTimestampMs() + 200,
        'price' => 101.2,
    ]]);

    expect($result['status'])->toBe('evaluated')
        ->and($result['predicted_direction'])->toBe('buy')
        ->and($result['actual_direction'])->toBe('buy')
        ->and($result['direction_correct'])->toBeTrue()
        ->and($result['price_in_predicted_range'])->toBeTrue();
});

it('does not fabricate an outcome when the expiry quote is missing', function () {
    $result = app(ForecastOutcomeEvaluator::class)->evaluate([
        'available' => true,
        'generated_at' => now()->subMinutes(5)->toIso8601String(),
        'horizon_seconds' => 300,
        'reference_price' => 100,
        'predicted_expiry_price' => 101,
    ], []);

    expect($result['status'])->toBe('missing_horizon_sample')
        ->and($result['actual_price'])->toBeNull()
        ->and($result['direction_correct'])->toBeNull();
});
