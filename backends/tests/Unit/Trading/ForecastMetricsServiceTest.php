<?php

use App\Domain\Trading\ForecastMetricsService;

it('keeps accuracy unavailable below the trusted sample floor and calculates MAE', function () {
    $rows = collect(range(1, 29))->map(fn ($i) => [
        'primary_comparison' => [
            'direction_correct' => true,
            'price_in_predicted_range' => $i % 2 === 0,
            'price_error' => $i,
        ],
        'net_pnl_booked' => $i > 20 ? 1 : -1,
    ]);
    $result = app(ForecastMetricsService::class)->summarize($rows);
    expect($result['direction_accuracy_pct'])->toBeNull()
        ->and($result['range_hit_rate_pct'])->toBeNull()
        ->and($result['price_mae'])->toBe(15.0)
        ->and($result['status'])->toBe('insufficient_data');
});

it('suppresses every new advanced metric below the trusted sample floor', function () {
    // Only 10 net_pnl rows -- well under MIN_TRUSTED_SAMPLES (30) and under
    // MIN_DRAWDOWN_SEQUENCE_POINTS's own floor is cleared (5), but the
    // rate/average metrics gated by the 30-sample floor must still be null.
    $rows = collect(range(1, 10))->map(fn ($i) => ['net_pnl_booked' => $i % 2 === 0 ? 5 : -3]);
    $result = app(ForecastMetricsService::class)->summarize($rows);

    expect($result['profit_factor'])->toBeNull()
        ->and($result['avg_win'])->toBeNull()
        ->and($result['avg_loss'])->toBeNull()
        ->and($result['sharpe_like_ratio'])->toBeNull()
        // Drawdown has its own, lower floor (5) and 10 rows clears it, so
        // it IS reported even though the 30-sample-gated metrics are not.
        ->and($result['max_drawdown_amount'])->not->toBeNull();
});

it('computes profit factor, avg win/loss and a Sharpe-like ratio from a known, hand-computable sequence', function () {
    // 20 wins of +10, 15 losses of -4 -- 35 rows total, clears the 30-sample floor.
    $rows = collect()
        ->concat(collect(range(1, 20))->map(fn () => ['net_pnl_booked' => 10.0]))
        ->concat(collect(range(1, 15))->map(fn () => ['net_pnl_booked' => -4.0]));

    $result = app(ForecastMetricsService::class)->summarize($rows);

    // gross_profit = 200, gross_loss = 60 -> profit_factor = 200/60
    expect($result['profit_factor'])->toBe(round(200 / 60, 4))
        ->and($result['avg_win'])->toBe(10.0)
        ->and($result['avg_loss'])->toBe(-4.0);

    // mean = (200 - 60) / 35 = 4.0
    $values = array_merge(array_fill(0, 20, 10.0), array_fill(0, 15, -4.0));
    $mean = array_sum($values) / count($values);
    $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / (count($values) - 1);
    $stdev = sqrt($variance);
    expect($result['sharpe_like_ratio'])->toBe(round($mean / $stdev, 4));
});

it('reports profit factor as null (undefined) when there are no losing rows at all', function () {
    $rows = collect(range(1, 30))->map(fn () => ['net_pnl_booked' => 5.0]);
    $result = app(ForecastMetricsService::class)->summarize($rows);

    expect($result['profit_factor'])->toBeNull()
        ->and($result['avg_win'])->toBe(5.0)
        ->and($result['avg_loss'])->toBeNull();
});

it('computes max drawdown from a known chronological P&L sequence ordered by settled_at', function () {
    // Chronological P&L sequence: +10, +10, -25, +5, -5
    // Cumulative:                  10,  20,  -5, 0,  -5
    // Peak tracked:                10,  20,  20, 20, 20
    // Drawdown:                     0,   0,  25, 20, 25
    // -> max_drawdown_amount = 25, at peak 20 -> pct = 125%
    $base = \Carbon\CarbonImmutable::parse('2026-01-01T00:00:00Z');
    $pnls = [10, 10, -25, 5, -5];
    // Shuffle the array order but keep settled_at correct -- proves
    // ordering is by settled_at, not by row insertion order.
    $rows = collect([4, 0, 3, 1, 2])->map(fn ($i) => [
        'net_pnl_booked' => $pnls[$i],
        'settled_at' => $base->addMinutes($i)->toIso8601String(),
    ]);

    $result = app(ForecastMetricsService::class)->summarize($rows);

    expect($result['max_drawdown_amount'])->toBe(25.0)
        ->and($result['max_drawdown_pct'])->toBe(125.0);
});

it('does not report a drawdown below the dedicated drawdown sample floor', function () {
    $rows = collect([1, 2, 3])->map(fn ($i) => ['net_pnl_booked' => $i % 2 === 0 ? 10 : -10]);
    $result = app(ForecastMetricsService::class)->summarize($rows);

    expect($result['max_drawdown_amount'])->toBeNull()
        ->and($result['max_drawdown_pct'])->toBeNull();
});

it('breaks direction accuracy down by buy vs sell vs neutral (WAIT) with a frequency figure', function () {
    // Each bucket's OWN evaluated count must independently clear the
    // 30-sample floor for its accuracy_pct to be trusted -- so buy/sell
    // buckets need >= 30 evaluated rows each, not just >= 30 combined.
    $rows = collect()
        ->concat(collect(range(1, 40))->map(fn ($i) => ['primary_comparison' => [
            'predicted_direction' => 'buy', 'direction_correct' => $i <= 30, // 30/40 = 75%
        ]]))
        ->concat(collect(range(1, 30))->map(fn ($i) => ['primary_comparison' => [
            'predicted_direction' => 'sell', 'direction_correct' => $i <= 6, // 6/30 = 20%
        ]]))
        ->concat(collect(range(1, 30))->map(fn () => ['primary_comparison' => [
            'predicted_direction' => 'neutral', 'direction_correct' => null,
        ]]));

    $result = app(ForecastMetricsService::class)->summarize($rows);
    $breakdown = $result['direction_breakdown'];

    expect($breakdown['buy']['predictions'])->toBe(40)
        ->and($breakdown['buy']['accuracy_pct'])->toBe(75.0)
        ->and($breakdown['buy']['frequency_pct'])->toBe(40.0)
        ->and($breakdown['sell']['predictions'])->toBe(30)
        ->and($breakdown['sell']['accuracy_pct'])->toBe(20.0)
        ->and($breakdown['sell']['frequency_pct'])->toBe(30.0)
        ->and($breakdown['neutral']['predictions'])->toBe(30)
        ->and($breakdown['neutral']['accuracy_pct'])->toBeNull() // no evaluated rows (all null direction_correct)
        ->and($breakdown['neutral']['frequency_pct'])->toBe(30.0);
});
