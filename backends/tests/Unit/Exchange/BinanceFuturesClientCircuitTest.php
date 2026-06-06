<?php

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Exchange\BinanceRestCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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

it('opens the shared Binance REST ban circuit when Binance reports an IP ban', function () {
    Cache::forget(BinanceRestCircuitBreaker::BAN_UNTIL_KEY);
    Cache::forget(BinanceRestCircuitBreaker::LAST_ERROR_KEY);

    Http::fake([
        'https://fapi.binance.com/*' => Http::response([
            'code' => -1003,
            'msg' => 'Way too many requests; IP banned until 1780759079870.',
        ], 429),
    ]);

    $client = new BinanceFuturesClient('key', 'secret', 'https://fapi.binance.com');

    try {
        $client->ping();
    } catch (Throwable) {
        // assertSuccess throws after the response is recorded by the guard.
    }

    expect((int) Cache::get(BinanceRestCircuitBreaker::BAN_UNTIL_KEY))->toBe(1780759079)
        ->and((string) Cache::get(BinanceRestCircuitBreaker::LAST_ERROR_KEY))->toContain('-1003');
});
