<?php

use App\Domain\Trading\FuturesOrderSizingService;

test('it rounds quantity down and enforces binance futures min open notional floor', function () {
    $result = app(FuturesOrderSizingService::class)->evaluate(0.00012678, 80000, [
        'stepSize' => 0.0001,
        'minNotional' => 5,
    ]);

    expect($result['quantity_float'])->toBe(0.0001)
        ->and($result['quantity'])->toBe('0.0001')
        ->and($result['notional'])->toBe(8.0)
        ->and($result['minOpenNotional'])->toBe(20.0);
});

test('reduce only sizing bypasses the minimum open notional guard', function () {
    $result = app(FuturesOrderSizingService::class)->evaluate(0.00012678, 80000, [
        'stepSize' => 0.0001,
        'minNotional' => 5,
    ], true);

    expect($result['quantity_float'])->toBe(0.0001)
        ->and($result['minOpenNotional'])->toBe(0.0);
});
