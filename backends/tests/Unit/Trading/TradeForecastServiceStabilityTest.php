<?php

use App\Domain\Trading\TradeForecastService;

/**
 * Covers TradeForecastService's forecast_stability signal (spec item 76): a
 * model flipping BUY -> SELL -> BUY every few evaluations should not present
 * high confidence without a visible penalty. forecastStability() is private
 * (an internal companion to forecast()); it is exercised directly via
 * reflection here so the flip sequence fed into it is fully deterministic,
 * and once more through the real public forecast() call to prove the field
 * is actually wired into the response.
 */
function invokeForecastStability(TradeForecastService $service, ?string $symbol, string $timeframe, int $horizonSeconds, string $decisionTrend, ?\Carbon\Carbon $generatedAt = null): array
{
    $method = new ReflectionMethod($service, 'forecastStability');
    $method->setAccessible(true);

    return $method->invoke($service, $symbol, $timeframe, $horizonSeconds, $decisionTrend, $generatedAt ?? now());
}

function stabilityTestCandles(): array
{
    $candles = [];
    $price = 100.0;
    for ($i = 0; $i < 30; $i++) {
        $close = $price + 1.0;
        $candles[] = [
            'open' => $price,
            'high' => $close + 0.2,
            'low' => $price - 0.2,
            'close' => $close,
            'volume' => 1000,
        ];
        $price = $close;
    }

    return $candles;
}

it('labels a stable, non-flipping sequence of decisions HIGH', function () {
    $service = app(TradeForecastService::class);
    $tick = now();

    $result = null;
    foreach (['BUY', 'BUY', 'BUY', 'BUY', 'BUY'] as $decision) {
        $result = invokeForecastStability($service, 'BTCUSDT_STABLE', '1m', 60, $decision, $tick);
        $tick = $tick->copy()->addSecond();
    }

    expect($result['flips'])->toBe(0);
    expect($result['label'])->toBe('HIGH');
});

it('labels an every-tick-alternating sequence of decisions LOW', function () {
    $service = app(TradeForecastService::class);
    $tick = now();

    $result = null;
    foreach (['BUY', 'SELL', 'BUY', 'SELL', 'BUY'] as $decision) {
        $result = invokeForecastStability($service, 'ETHUSDT_FLIP', '1m', 60, $decision, $tick);
        $tick = $tick->copy()->addSecond();
    }

    expect($result['flips'])->toBe(4);
    expect($result['label'])->toBe('LOW');
});

it('labels a moderately flip-flopping sequence MEDIUM', function () {
    $service = app(TradeForecastService::class);
    $tick = now();

    $result = null;
    foreach (['BUY', 'BUY', 'SELL', 'SELL', 'BUY'] as $decision) {
        $result = invokeForecastStability($service, 'BNBUSDT_MED', '1m', 60, $decision, $tick);
        $tick = $tick->copy()->addSecond();
    }

    expect($result['flips'])->toBe(2);
    expect($result['label'])->toBe('MEDIUM');
});

it('never fabricates a stability label when no symbol scope is supplied', function () {
    $service = app(TradeForecastService::class);

    $result = invokeForecastStability($service, null, '1m', 60, 'BUY');

    expect($result['label'])->toBe('UNKNOWN');
    expect($result['flips'])->toBeNull();
});

it('does not alter decision_trend/confidence when surfacing an unstable forecast_stability signal', function () {
    Carbon\Carbon::setTestNow(now());
    $candles = stabilityTestCandles();
    $service = app(TradeForecastService::class);

    $first = $service->forecast($candles, '1m', 60, null, null, null, 'ADAUSDT_WIRE');
    Carbon\Carbon::setTestNow(now()->addSecond());
    $second = $service->forecast($candles, '1m', 60, null, null, null, 'ADAUSDT_WIRE');

    // Identical inputs must still produce the identical decision_trend/
    // confidence regardless of what forecast_stability reports -- this task
    // only surfaces the signal, it must never re-derive the decision itself.
    expect($second['decision_trend'])->toBe($first['decision_trend']);
    expect($second['confidence'])->toBe($first['confidence']);
    expect($second)->toHaveKey('forecast_stability');
    expect($second['forecast_stability_window'])->toBe(2);

    Carbon\Carbon::setTestNow();
});

it('wires forecast_stability into the real forecast() response for a symbol-scoped, non-flipping sequence', function () {
    Carbon\Carbon::setTestNow(now());
    $candles = stabilityTestCandles();
    $service = app(TradeForecastService::class);

    $last = null;
    for ($i = 0; $i < 5; $i++) {
        $last = $service->forecast($candles, '1m', 60, null, null, null, 'SOLUSDT_INTEGRATION');
        Carbon\Carbon::setTestNow(now()->addSecond());
    }

    expect($last['forecast_stability'])->toBe('HIGH');
    expect($last['forecast_stability_flips'])->toBe(0);
    expect($last['forecast_stability_window'])->toBe(5);

    Carbon\Carbon::setTestNow();
});
