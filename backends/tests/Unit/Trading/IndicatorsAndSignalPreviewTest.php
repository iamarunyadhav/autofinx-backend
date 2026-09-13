<?php

use App\Domain\Market\Indicators\Indicators;
use App\Domain\Trading\SignalPreviewService;

test('extended indicators return deterministic latest values', function () {
    $candles = [];
    foreach ([100, 101, 102, 103, 104, 105, 103, 101, 99, 96, 94, 92, 91, 90, 89, 88, 87, 86, 85, 84, 83, 82] as $index => $close) {
        $candles[] = [
            'open' => $close + 0.2,
            'high' => $close + 0.8,
            'low' => $close - 0.8,
            'close' => $close,
            'volume' => 100 + $index,
        ];
    }

    $closes = array_column($candles, 'close');

    $macd = Indicators::macd($closes, 5, 8, 4);
    $cci = Indicators::cci($candles, 10);
    $stochastic = Indicators::stochastic($candles, 8, 3);
    $heikinAshi = Indicators::heikinAshi($candles);

    expect($macd)->toBeArray()
        ->and($macd['line'])->toBeFloat()
        ->and($macd['signal'])->toBeFloat()
        ->and($cci)->toBeFloat()
        ->and($stochastic)->toBeArray()
        ->and($stochastic['k'])->toBeFloat()
        ->and($stochastic['d'])->toBeFloat()
        ->and($heikinAshi)->toHaveCount(count($candles))
        ->and($heikinAshi[0])->toHaveKeys(['open', 'high', 'low', 'close']);
});

test('signal preview returns buy when bearish exhaustion dominates', function () {
    $candles = [];
    foreach ([100, 101, 100, 99, 98, 96, 95, 94, 92, 90, 88, 86, 85, 84, 83, 82, 81, 80, 79, 78, 77, 76, 75] as $index => $close) {
        $candles[] = [
            'open' => $close + 0.3,
            'high' => $close + 0.9,
            'low' => $close - 0.9,
            'close' => $close,
            'volume' => 200 + $index,
        ];
    }

    $preview = app(SignalPreviewService::class)->preview($candles, '1m', ['RSI', 'BOLLINGER', 'CCI', 'STOCHASTIC']);

    expect($preview['direction'])->toBe('buy')
        ->and($preview['confidence'])->toBeGreaterThan(40)
        ->and($preview['supporting_indicators'])->not->toBeEmpty();
});

test('adxWithDi returns the same adx value as the existing scalar adx() for an identical candle series', function () {
    $candles = [];
    $close = 100.0;
    foreach (range(1, 40) as $i) {
        $close += ($i % 2 === 0) ? 1.5 : -0.5; // net uptrend with noise
        $candles[] = [
            'open' => $close - 0.3,
            'high' => $close + 1.0,
            'low' => $close - 1.0,
            'close' => $close,
        ];
    }

    $scalarAdx = Indicators::adx($candles, 14);
    $withDi = Indicators::adxWithDi($candles, 14);

    expect($withDi)->toBeArray()
        ->and($withDi['adx'])->toEqualWithDelta($scalarAdx, 0.0000001)
        ->and($withDi['plus_di'])->toBeFloat()
        ->and($withDi['minus_di'])->toBeFloat()
        // Net uptrend -> +DI should dominate -DI.
        ->and($withDi['plus_di'])->toBeGreaterThan($withDi['minus_di']);
});

test('adxWithDi returns null for insufficient candle history, matching adx()', function () {
    $candles = array_fill(0, 5, ['open' => 100, 'high' => 101, 'low' => 99, 'close' => 100]);

    expect(Indicators::adxWithDi($candles, 14))->toBeNull()
        ->and(Indicators::adx($candles, 14))->toBeNull();
});
