<?php

use App\Domain\Exchange\BinanceSpotClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('resyncs the server time offset on -1021 and successfully retries once', function () {
    $baseUrl = 'https://api.binance.com';
    $offsetKey = 'autofinx:binance:spot_time_offset:' . md5($baseUrl);
    Cache::forget($offsetKey);

    $futureServerTimeMs = (int) floor((microtime(true) + 3600) * 1000); // 1h ahead, clearly a real skew

    Http::fake([
        '*/api/v3/account*' => Http::sequence()
            ->push(['code' => -1021, 'msg' => 'Timestamp for this request was outside of the recvWindow.'], 400)
            ->push(['balances' => []], 200),
        '*/api/v3/time*' => Http::response(['serverTime' => $futureServerTimeMs]),
    ]);

    $client = new BinanceSpotClient('key', 'secret', $baseUrl, 'wss://stream.binance.com:9443');

    $balances = $client->fetchBalances();

    expect($balances)->toBe([])
        ->and((int) Cache::get($offsetKey, 0))->toBeGreaterThan(1_000_000); // ~1h in ms, well above noise
});

it('does not resync when requests succeed normally', function () {
    $baseUrl = 'https://api.binance.com';
    $offsetKey = 'autofinx:binance:spot_time_offset:' . md5($baseUrl);
    Cache::forget($offsetKey);

    Http::fake([
        '*/api/v3/account*' => Http::response(['balances' => []], 200),
        '*/api/v3/time*' => Http::response(['serverTime' => 0]),
    ]);

    $client = new BinanceSpotClient('key', 'secret', $baseUrl, 'wss://stream.binance.com:9443');
    $client->fetchBalances();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v3/time'));
});
