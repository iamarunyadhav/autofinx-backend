<?php

namespace App\Domain\MarketOpportunityBrain\Adapters;

/**
 * Pages Binance PUBLIC klines for a bounded, closed, historical window (read-only, no API key).
 * Refuses any window that ends at/after the sealed-holdout boundary or in the future.
 */
final class BinancePublicHistoryFetcher
{
    public function __construct(private readonly BinancePublicRestClient $client = new BinancePublicRestClient())
    {
    }

    /** @return array<int,array<int,mixed>> ascending, de-duplicated, closed bars with openMs in [startMs, endMs] */
    public function fetch(string $symbol, string $interval, int $intervalMs, int $startMs, int $endMs, int $sealEndMs): array
    {
        if ($endMs >= $sealEndMs) {
            throw new \InvalidArgumentException('Requested window reaches the sealed holdout; refused.');
        }
        if ($endMs >= (int) (microtime(true) * 1000)) {
            throw new \InvalidArgumentException('Requested window is not fully in the past; refused.');
        }
        $out = [];
        $cursor = $startMs;
        $guard = 0;
        while ($cursor <= $endMs && $guard++ < 50) {
            $rows = $this->client->klines($symbol, $interval, 1500, $cursor, $endMs);
            if ($rows === []) {
                break;
            }
            $last = $cursor;
            foreach ($rows as $row) {
                if (! is_array($row) || count($row) < 7 || (int) $row[0] > $endMs) {
                    continue;
                }
                $out[(int) $row[0]] = $row;
                $last = max($last, (int) $row[0]);
            }
            if ($last < $cursor || count($rows) < 1500) {
                break;
            }
            $cursor = $last + $intervalMs;
        }
        ksort($out);

        return array_values($out);
    }
}
