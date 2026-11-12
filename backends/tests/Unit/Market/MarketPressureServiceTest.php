<?php

use App\Domain\Market\MarketPressureService;

it('classifies buyer-maker trades as seller aggressed and computes pressure', function () {
    $service = new MarketPressureService;
    $now = 1_800_000_000_000;

    $service->ingestAggregateTrade(['s' => 'BTCUSDT', 'a' => 1, 'T' => $now - 500, 'p' => '100', 'q' => '2', 'm' => false], $now);
    $service->ingestAggregateTrade(['s' => 'BTCUSDT', 'a' => 2, 'T' => $now - 400, 'p' => '100', 'q' => '1', 'm' => true], $now);
    $snapshot = $service->snapshot('BTCUSDT', '1m', $now);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot['buy_notional'])->toBe(200.0)
        ->and($snapshot['sell_notional'])->toBe(100.0)
        ->and(round($snapshot['buy_pressure_pct'], 2))->toBe(66.67)
        ->and(round($snapshot['sell_pressure_pct'], 2))->toBe(33.33)
        ->and($snapshot['cvd_notional'])->toBe(100.0)
        ->and($snapshot['trade_count'])->toBe(2)
        ->and($snapshot['is_prediction'])->toBeFalse();
});

it('deduplicates aggregate trade ids and accepts bounded out of order events', function () {
    $service = new MarketPressureService;
    $now = 1_800_000_000_000;
    $row = ['s' => 'ETHUSDT', 'a' => 10, 'T' => $now - 1000, 'p' => '2000', 'q' => '1', 'm' => false];

    expect($service->ingestAggregateTrade($row, $now))->toBeTrue()
        ->and($service->ingestAggregateTrade($row, $now))->toBeFalse()
        ->and($service->ingestAggregateTrade(['s' => 'ETHUSDT', 'a' => 11, 'T' => $now - 2000, 'p' => '2000', 'q' => '1', 'm' => true], $now))->toBeTrue();

    expect($service->snapshot('ETHUSDT', '1m', $now)['trade_count'])->toBe(2);
});

it('rejects malformed future and expired events', function () {
    $service = new MarketPressureService;
    $now = 1_800_000_000_000;

    expect($service->ingestAggregateTrade([], $now))->toBeFalse()
        ->and($service->ingestAggregateTrade(['s' => 'BTCUSDT', 'a' => 1, 'T' => $now + 6000, 'p' => '1', 'q' => '1'], $now))->toBeFalse()
        ->and($service->ingestAggregateTrade(['s' => 'BTCUSDT', 'a' => 2, 'T' => $now - 901000, 'p' => '1', 'q' => '1'], $now))->toBeFalse();
});

it('computes explicitly labelled top five visible order book imbalance', function () {
    $service = new MarketPressureService;
    $now = 1_800_000_000_000;
    $service->ingestPartialDepth([
        's' => 'BTCUSDT', 'E' => $now, 'u' => 55,
        'b' => [['100', '2'], ['99', '1']],
        'a' => [['101', '1'], ['102', '1']],
    ], $now);
    $service->ingestAggregateTrade(['s' => 'BTCUSDT', 'a' => 1, 'T' => $now, 'p' => '100', 'q' => '1', 'm' => false], $now);

    $book = $service->snapshot('BTCUSDT', '1m', $now)['order_book'];
    expect($book['kind'])->toBe('partial_depth_top5')
        ->and($book['best_bid'])->toBe(100.0)
        ->and($book['best_ask'])->toBe(101.0)
        ->and($book['imbalance'])->toBeGreaterThan(0)
        ->and($book['sequence'])->toBe(55);
});
