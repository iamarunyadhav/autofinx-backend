<?php

use App\Domain\Market\MarketTransportCoalescer;

function transportQuote(string $symbol, int $eventMs, string $price): array
{
    return ['stream' => strtolower($symbol).'@bookTicker', 'data' => [
        'e' => 'bookTicker', 's' => $symbol, 'E' => $eventMs, 'b' => $price, 'a' => $price,
    ]];
}

function transportKline(string $symbol, int $eventMs, bool $closed, string $close = '100'): array
{
    return ['stream' => strtolower($symbol).'@kline_1m', 'data' => [
        'e' => 'kline', 's' => $symbol, 'E' => $eventMs,
        'k' => ['t' => $eventMs - 1000, 'T' => $eventMs, 'i' => '1m', 'o' => '99',
            'h' => '101', 'l' => '98', 'c' => $close, 'v' => '10', 'x' => $closed],
    ]];
}

it('keeps only the newest quote per symbol until the bounded drain', function () {
    $buffer = new MarketTransportCoalescer(100, 1000);
    expect($buffer->offer(transportQuote('BTCUSDT', 1010, '100'), 1010))->toBe([]);
    expect($buffer->offer(transportQuote('BTCUSDT', 1020, '101'), 1020))->toBe([]);
    $frames = $buffer->drainIfDue(1100);

    expect($frames)->toHaveCount(1)
        ->and($frames[0]['data']['b'])->toBe('101')
        ->and($buffer->stats()['coalesced'])->toBe(1)
        ->and($buffer->stats()['max_buffered'])->toBe(1);
});

it('isolates symbols and candle timeframes', function () {
    $buffer = new MarketTransportCoalescer(100, 1000);
    $buffer->offer(transportQuote('BTCUSDT', 1010, '100'), 1010);
    $buffer->offer(transportQuote('ETHUSDT', 1011, '200'), 1011);
    $buffer->offer(transportKline('BTCUSDT', 1012, false), 1012);

    expect($buffer->drain(1050))->toHaveCount(3);
});

it('never buffers or drops a closed candle', function () {
    $buffer = new MarketTransportCoalescer(100, 1000);
    $buffer->offer(transportKline('BTCUSDT', 1010, false, '100'), 1010);
    $closed = transportKline('BTCUSDT', 1020, true, '102');

    expect($buffer->offer($closed, 1020))->toBe([$closed])
        ->and($buffer->stats()['buffered'])->toBe(0);
});

it('cannot let an older event replace a newer pending event', function () {
    $buffer = new MarketTransportCoalescer(100, 1000);
    $buffer->offer(transportQuote('BTCUSDT', 1050, '105'), 1010);
    $buffer->offer(transportQuote('BTCUSDT', 1040, '104'), 1020);
    $frames = $buffer->drain(1050);

    expect($frames[0]['data']['b'])->toBe('105')
        ->and($buffer->stats()['older_dropped'])->toBe(1);
});
