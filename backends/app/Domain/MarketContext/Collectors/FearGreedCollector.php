<?php

namespace App\Domain\MarketContext\Collectors;

use App\Domain\MarketContext\Data\CollectResult;
use App\Domain\MarketContext\Data\RawMetric;
use App\Domain\MarketContext\Support\AssetClass;
use Illuminate\Support\Carbon;

/**
 * alternative.me Fear & Greed. Real response (verified 2026-10-06):
 * {"name":..,"data":[{"value":"73","value_classification":"Greed","timestamp":"1791244800","time_until_update":".."}],"metadata":{"error":null}}
 * timestamp = 00:00 UTC of the index day, newest first. Market-wide metric.
 */
final class FearGreedCollector extends BaseCollector
{
    public const METRIC = 'fear_greed';

    public function collect(?string $etag, ?string $lastModified, Carbon $now): CollectResult
    {
        $result = $this->expectOk($this->fetcher->get($this->config['url'].'?limit=3&format=json', $etag, $lastModified));
        if ($result->notModified()) {
            return new CollectResult(notModified: true, etag: $etag, lastModified: $lastModified);
        }

        // Live rows were first seen now; observed_at is the index day.
        return new CollectResult(metrics: $this->parse($result->body, null), etag: $result->etag,
            lastModified: $result->lastModified, contentHash: hash('sha256', $result->body));
    }

    /**
     * History backfill. The history is stamped by index day, not by the moment
     * alternative.me published it, so first_seen_at is observed_at plus
     * backfill_lag_hours (default 24h) rather than the day start: a backtest
     * never sees a value earlier than we can justify.
     */
    public function backfill(int $days, Carbon $now): CollectResult
    {
        $days = max(1, min($days, (int) config('market_context.limits.fear_greed_backfill_max_days', 1500)));
        $result = $this->expectOk($this->fetcher->get($this->config['url'].'?limit='.$days.'&format=json'));

        return new CollectResult(metrics: $this->parse($result->body, (int) ($this->config['backfill_lag_hours'] ?? 24)),
            contentHash: hash('sha256', $result->body));
    }

    /** @return list<RawMetric> */
    private function parse(string $body, ?int $lagHours): array
    {
        $json = json_decode($body, true, 16);
        if (! is_array($json) || ! isset($json['data']) || ! is_array($json['data']) || ($json['metadata']['error'] ?? null) !== null) {
            throw new SourceFetchException('BAD_FORMAT');
        }
        $metrics = [];
        foreach (array_slice($json['data'], 0, (int) config('market_context.limits.fear_greed_backfill_max_days', 1500)) as $row) {
            if (! isset($row['value'], $row['timestamp']) || ! is_numeric($row['value']) || ! is_numeric($row['timestamp'])) {
                continue;
            }
            $value = (float) $row['value'];
            if ($value < 0 || $value > 100) {
                continue;
            }
            $observed = Carbon::createFromTimestampUTC((int) $row['timestamp']);
            $metrics[] = new RawMetric($this->source, AssetClass::CRYPTO, AssetClass::MARKET_WIDE, self::METRIC, $value, $observed,
                $lagHours === null ? null : $observed->copy()->addHours($lagHours));
        }
        if ($metrics === []) {
            throw new SourceFetchException('EMPTY');
        }

        return $metrics;
    }
}
