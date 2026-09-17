<?php

use App\Domain\Exchange\BinanceFuturesClient;
use App\Domain\Trading\LeverageValidationService;
use Illuminate\Support\Facades\Http;

function leverageValidationClient(): BinanceFuturesClient
{
    return new BinanceFuturesClient('test-key', 'test-secret', 'https://testnet.binancefuture.com');
}

test('a request within the real bracket limit for its own notional is allowed', function () {
    Http::fake([
        '*/fapi/v1/leverageBracket*' => Http::response([
            'symbol' => 'ETHUSDT',
            'brackets' => [
                ['bracket' => 1, 'initialLeverage' => 20, 'notionalCap' => 50000, 'notionalFloor' => 0],
                ['bracket' => 2, 'initialLeverage' => 10, 'notionalCap' => 250000, 'notionalFloor' => 50000],
            ],
        ], 200),
    ]);

    $result = app(LeverageValidationService::class)->validate(leverageValidationClient(), 'ETHUSDT', 10.0, 200.0);

    expect($result['allowed'])->toBeTrue()
        ->and($result['real_max_for_notional'])->toBe(20.0)
        ->and($result['reason'])->toBeNull();
});

test('a request exceeding the real tier max for its own notional is rejected, never clamped, and reports the real max', function () {
    Http::fake([
        '*/fapi/v1/leverageBracket*' => Http::response([
            'symbol' => 'ETHUSDT',
            'brackets' => [
                ['bracket' => 1, 'initialLeverage' => 15, 'notionalCap' => 50000, 'notionalFloor' => 0],
                ['bracket' => 2, 'initialLeverage' => 10, 'notionalCap' => 250000, 'notionalFloor' => 50000],
            ],
        ], 200),
    ]);

    $result = app(LeverageValidationService::class)->validate(leverageValidationClient(), 'ETHUSDT', 18.0, 200.0);

    expect($result['allowed'])->toBeFalse()
        ->and($result['reason'])->toBe(LeverageValidationService::REASON_EXCEEDS_MAX)
        // Real max reported, not silently substituted for the requested value.
        ->and($result['real_max_for_notional'])->toBe(15.0);
});

test('a request is checked against the tier matching its OWN notional, not the symbol best-case tier', function () {
    Http::fake([
        '*/fapi/v1/leverageBracket*' => Http::response([
            'symbol' => 'ETHUSDT',
            'brackets' => [
                ['bracket' => 1, 'initialLeverage' => 50, 'notionalCap' => 10000, 'notionalFloor' => 0],
                ['bracket' => 2, 'initialLeverage' => 10, 'notionalCap' => 250000, 'notionalFloor' => 10000],
            ],
        ], 200),
    ]);

    // 20x requested against a LARGE notional that falls into the second
    // (lower-leverage) tier — must be rejected even though the symbol's
    // best-case tier (small notional) allows 50x.
    $result = app(LeverageValidationService::class)->validate(leverageValidationClient(), 'ETHUSDT', 20.0, 50000.0);

    expect($result['allowed'])->toBeFalse()
        ->and($result['real_max_for_notional'])->toBe(10.0);
});

test('an API failure fails closed: rejected, not defaulted-permissive', function () {
    Http::fake([
        '*/fapi/v1/leverageBracket*' => Http::response(['msg' => 'Internal error'], 500),
    ]);

    $result = app(LeverageValidationService::class)->validate(leverageValidationClient(), 'ETHUSDT', 5.0, 200.0);

    expect($result['allowed'])->toBeFalse()
        ->and($result['reason'])->toBe(LeverageValidationService::REASON_UNABLE_TO_VERIFY)
        ->and($result['real_max_for_notional'])->toBeNull();
});

test('a malformed bracket shape fails closed instead of being partially trusted', function () {
    Http::fake([
        '*/fapi/v1/leverageBracket*' => Http::response([
            'symbol' => 'ETHUSDT',
            'brackets' => [
                ['bracket' => 1, 'initialLeverage' => 20], // missing notionalCap/notionalFloor
            ],
        ], 200),
    ]);

    $result = app(LeverageValidationService::class)->validate(leverageValidationClient(), 'ETHUSDT', 5.0, 200.0);

    expect($result['allowed'])->toBeFalse()
        ->and($result['reason'])->toBe(LeverageValidationService::REASON_UNABLE_TO_VERIFY);
});

test('a network exception during the bracket lookup fails closed', function () {
    Http::fake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('connection refused');
    });

    $result = app(LeverageValidationService::class)->validate(leverageValidationClient(), 'ETHUSDT', 5.0, 200.0);

    expect($result['allowed'])->toBeFalse()
        ->and($result['reason'])->toBe(LeverageValidationService::REASON_UNABLE_TO_VERIFY);
});
