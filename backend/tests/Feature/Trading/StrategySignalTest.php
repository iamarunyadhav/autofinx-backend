<?php

use App\Domain\Strategies\Implementations\MaRsiStrategy;
use App\Domain\Strategies\Implementations\BollingerReversionStrategy;
use App\Domain\Market\Indicators\Indicators;

test('ma rsi generates buy on crossover with rsi low', function () {
    $strategy = new MaRsiStrategy();
    $candles = [];
    $prices = [99,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114,115,116,117,118,119,120];
    foreach ($prices as $i => $p) {
        $candles[] = ['close' => $p, 'open' => $p, 'high' => $p, 'low' => $p, 'volume' => 1, 'opens_at' => now()->subMinutes(count($prices) - $i)];
    }
    $signal = $strategy->evaluate($candles, [
        'fast_period' => 3,
        'slow_period' => 5,
        'rsi_period' => 14,
        'rsi_buy_below' => 101, // high threshold to force BUY
        'rsi_sell_above' => 80,
    ]);
    expect($signal->action)->toBe('BUY');
});

test('bollinger produces sell above upper band', function () {
    $strategy = new BollingerReversionStrategy();
    $candles = [];
    for ($i = 0; $i < 30; $i++) {
        $p = $i < 29 ? 100 : 150;
        $candles[] = ['close' => $p, 'open' => $p, 'high' => $p, 'low' => $p, 'volume' => 1, 'opens_at' => now()->subMinutes(30 - $i)];
    }
    $signal = $strategy->evaluate($candles, ['period' => 20, 'stddev' => 2]);
    expect($signal->action)->toBe('SELL');
});
