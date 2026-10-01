<?php

namespace App\Domain\Ml\Shadow;

use App\Domain\Exchange\BinanceRestCircuitBreaker;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only public kline fetcher for the ML shadow pipeline.
 *
 * Goes through the shared BinanceRestCircuitBreaker so a 418/429 seen here
 * protects every other REST caller, and uses its own throttle scope so a
 * long backfill never queues behind (or delays) trading REST calls.
 */
class BinanceFuturesKlineClient
{
    // Back off once more than half the 1-minute request-weight budget is used.
    private const USED_WEIGHT_SOFT_LIMIT = 1200;

    public function __construct(private readonly BinanceRestCircuitBreaker $breaker) {}

    /** @return array<int, mixed> */
    public function klines(string $symbol, string $interval, int $startMs, int $endMs, int $limit = 1000): array
    {
        $this->breaker->assertAvailable();
        $this->breaker->throttle('ml_shadow');

        $response = Http::timeout((int) config('ml.binance.timeout_seconds', 15))
            ->acceptJson()
            ->get(rtrim((string) config('ml.binance.base_url', 'https://fapi.binance.com'), '/').'/fapi/v1/klines', [
                'symbol' => strtoupper($symbol),
                'interval' => $interval,
                'startTime' => $startMs,
                'endTime' => $endMs,
                'limit' => max(1, min(1000, $limit)),
            ]);

        $this->breaker->recordResponse($response);
        if ($response->failed()) {
            throw new RuntimeException(sprintf('Binance Futures klines failed for %s %s: HTTP %d', $symbol, $interval, $response->status()));
        }

        $usedWeight = (int) ($response->header('X-MBX-USED-WEIGHT-1M') ?: 0);
        if ($usedWeight > self::USED_WEIGHT_SOFT_LIMIT) {
            $this->pause((60 - ((int) date('s'))) * 1000);
        }

        $rows = $response->json();

        return is_array($rows) ? $rows : [];
    }

    protected function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
