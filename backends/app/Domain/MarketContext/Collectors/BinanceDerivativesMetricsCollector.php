<?php

namespace App\Domain\MarketContext\Collectors;

use App\Domain\Exchange\BinanceRestCircuitBreaker;
use App\Domain\MarketContext\Data\CollectResult;
use App\Domain\MarketContext\Data\RawMetric;
use App\Domain\MarketContext\Fetch\SafeHttpFetcher;
use App\Domain\MarketContext\Support\ProfileRegistry;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Binance USD-M public futures statistics (no key, read-only), verified 2026-10-06:
 *  openInterestHist            [{"symbol","sumOpenInterest","sumOpenInterestValue","timestamp":<ms>}]
 *  globalLongShortAccountRatio [{"symbol","longAccount","longShortRatio","shortAccount","timestamp":<ms>}]
 *  takerlongshortRatio         [{"buySellRatio","sellVol","buyVol","timestamp":<ms>}]
 * Every call goes through BinanceRestCircuitBreaker (shared ban state) with its
 * own throttle scope 'market_context' so trading's pacing is untouched. Only
 * COMPLETED buckets (timestamp + period <= now) are stored, and each is stored
 * once with first_seen_at = now, so a later revision of a bucket never leaks
 * into the past. Funding already lives in ml_funding and is not duplicated.
 */
final class BinanceDerivativesMetricsCollector extends BaseCollector
{
    private const ENDPOINTS = [
        'openInterestHist' => ['open_interest_usd' => 'sumOpenInterestValue'],
        'globalLongShortAccountRatio' => ['long_short_ratio' => 'longShortRatio'],
        'takerlongshortRatio' => ['taker_buy_sell_ratio' => 'buySellRatio'],
    ];

    private const PERIOD_SECONDS = ['5m' => 300, '15m' => 900, '30m' => 1800, '1h' => 3600, '2h' => 7200, '4h' => 14400];

    public function __construct(string $source, array $config, SafeHttpFetcher $fetcher,
        private readonly BinanceRestCircuitBreaker $breaker, private readonly ProfileRegistry $profiles)
    {
        parent::__construct($source, $config, $fetcher);
    }

    public function collect(?string $etag, ?string $lastModified, Carbon $now): CollectResult
    {
        $period = (string) ($this->config['period'] ?? '1h');
        $periodSeconds = self::PERIOD_SECONDS[$period] ?? throw new SourceFetchException('BAD_CONFIG');
        $buckets = max(1, min(30, (int) ($this->config['buckets'] ?? 6)));
        $metrics = [];
        $failed = 0;

        foreach ($this->profiles->usingCollector($this->source) as $instrument => $profile) {
            $symbol = (string) ($profile['binance_futures_symbol'] ?? '');
            if (preg_match('/^[A-Z0-9]{5,20}$/', $symbol) !== 1) {
                continue;
            }
            foreach (self::ENDPOINTS as $endpoint => $fields) {
                try {
                    $this->breaker->assertAvailable();
                    $this->breaker->throttle('market_context');
                    $result = $this->fetcher->get($this->config['url'].$endpoint.'?symbol='.$symbol.'&period='.$period.'&limit='.$buckets);
                    $this->breaker->recordResponse($result->response);
                    $this->expectOk($result);
                    foreach ($this->parse($result->body, $fields, $instrument, $profile['asset_class'], $periodSeconds, $now) as $metric) {
                        $metrics[] = $metric;
                    }
                } catch (RuntimeException $e) {
                    // Circuit open (ban/rate limit) must stop the whole run, not be retried per symbol.
                    if (str_contains($e->getMessage(), 'binance_rest_circuit_open')) {
                        throw new SourceFetchException('BINANCE_CIRCUIT_OPEN');
                    }
                    $failed++;
                }
            }
        }

        if ($metrics === [] && $failed > 0) {
            throw new SourceFetchException('ALL_REQUESTS_FAILED');
        }

        return new CollectResult(metrics: $metrics, failedParts: $failed);
    }

    /**
     * @param  array<string, string>  $fields  metric name => response field
     * @return list<RawMetric>
     */
    public function parse(string $body, array $fields, string $instrument, string $assetClass, int $periodSeconds, Carbon $now): array
    {
        $rows = json_decode($body, true, 16);
        if (! is_array($rows) || ($rows !== [] && ! array_is_list($rows))) {
            throw new SourceFetchException('BAD_FORMAT');
        }
        $metrics = [];
        foreach (array_slice($rows, 0, 30) as $row) {
            if (! is_array($row) || ! isset($row['timestamp']) || ! is_numeric($row['timestamp'])) {
                continue;
            }
            $observed = Carbon::createFromTimestampMsUTC((int) $row['timestamp']);
            if ($observed->copy()->addSeconds($periodSeconds)->greaterThan($now)) {
                continue; // bucket still open
            }
            foreach ($fields as $metric => $field) {
                if (isset($row[$field]) && is_numeric($row[$field])) {
                    $metrics[] = new RawMetric($this->source, $assetClass, $instrument, $metric, (float) $row[$field], $observed);
                }
            }
        }

        return $metrics;
    }
}
