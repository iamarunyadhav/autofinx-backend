<?php

use App\Domain\Exchange\BinanceFuturesClient;
use Illuminate\Support\Facades\Cache;

it('opens the Binance futures REST circuit after repeated retryable failures', function () {
    Cache::forget('binance_cb:fapi.binance.com');

    $client = new BinanceFuturesClient('key', 'secret', 'https://fapi.binance.com');
    $method = new ReflectionMethod(BinanceFuturesClient::class, 'circuitRecordFailure');
    $method->setAccessible(true);

    for ($i = 0; $i < 5; $i++) {
        $method->invoke($client, 'fapi.binance.com', 'HTTP 429: rate limit');
    }

    $state = Cache::get('binance_cb:fapi.binance.com');

    expect($state['open'] ?? false)->toBeTrue()
        ->and($state['failures'] ?? 0)->toBe(5)
        ->and($state['last_error'] ?? null)->toBe('HTTP 429: rate limit');
});

