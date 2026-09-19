<?php

namespace App\Domain\Market;

use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * market_ohlc only accumulates forward from whenever market:ws-consume
 * happens to be running for whatever streams it's subscribed to (default is
 * 1m candles only). RatioBotBrainService/IndicatorService both need >=30
 * rows for a bot's *exact* timeframe before they'll compute anything — for a
 * freshly-configured 1h/4h bot that can silently mean 30-120+ hours of wait
 * with no loud warning. This service seeds history directly from Binance's
 * public (no-auth) REST klines endpoint.
 */
class MarketOhlcBackfillService
{
    public const MIN_ROWS_THRESHOLD = 30;
    public const DEFAULT_BACKFILL_LIMIT = 500;

    private const VALID_INTERVALS = [
        '1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d', '3d', '1w', '1M',
    ];

    public function needsBackfill(string $exchange, string $symbol, string $timeframe, int $minRows = self::MIN_ROWS_THRESHOLD): bool
    {
        return MarketOhlc::query()
            ->where('exchange', $exchange)
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', $this->normalizeInterval($timeframe))
            ->count() < $minRows;
    }

    public function needsBackfillForConfig(RatioBotConfig $config, int $minRows = self::MIN_ROWS_THRESHOLD): bool
    {
        return $this->needsBackfill($config->exchangeKey(), $config->normalizedSymbol(), $config->timeFrame(), $minRows);
    }

    /**
     * Fetch up to $limit historical candles from Binance's public REST
     * klines endpoint and upsert into market_ohlc, honoring the existing
     * (exchange, symbol, timeframe, ts) unique key so it can never duplicate
     * or conflict with rows market:ws-consume later writes for the same
     * candle.
     */
    public function backfill(string $exchange, string $symbol, string $timeframe, int $limit = self::DEFAULT_BACKFILL_LIMIT, bool $futures = false): int
    {
        $normalizedSymbol = strtoupper(trim($symbol));
        $interval = $this->normalizeInterval($timeframe);
        $limit = max(30, min(1000, $limit));

        $baseUrl = $futures
            ? rtrim((string) config('services.binance.futures_api_url', 'https://fapi.binance.com'), '/')
            : rtrim((string) config('services.binance.api_url', 'https://api.binance.com'), '/');
        $path = $futures ? '/fapi/v1/klines' : '/api/v3/klines';

        $response = Http::timeout(15)->get($baseUrl . $path, [
            'symbol' => $normalizedSymbol,
            'interval' => $interval,
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Binance klines backfill failed for {$normalizedSymbol} {$interval}: " . $response->body());
        }

        $written = 0;
        $minTs = null;
        $maxTs = null;
        foreach ((array) $response->json() as $row) {
            if (! is_array($row) || count($row) < 6) {
                continue;
            }

            // Explicit 'UTC' timezone (rather than relying on the app's
            // default timezone matching UTC) so this stays correct even if
            // APP_TIMEZONE is ever changed away from UTC in some
            // environment -- Binance's kline open-time is always a UTC
            // epoch millisecond value, and RealtimeMarketFeedService (the
            // live consumer writing to the same table) must be compared
            // against the same absolute instants for the no-lookahead
            // backtest guarantee to mean anything.
            $openTs = Carbon::createFromTimestampMs((int) $row[0], 'UTC');
            $existingId = MarketOhlc::query()
                ->where('exchange', $exchange)
                ->where('symbol', $normalizedSymbol)
                ->where('timeframe', $interval)
                ->where('ts', $openTs)
                ->value('id');

            MarketOhlc::updateOrCreate(
                ['exchange' => $exchange, 'symbol' => $normalizedSymbol, 'timeframe' => $interval, 'ts' => $openTs],
                [
                    'id' => $existingId ?: (string) Str::uuid(),
                    'open' => (float) $row[1],
                    'high' => (float) $row[2],
                    'low' => (float) $row[3],
                    'close' => (float) $row[4],
                    'volume' => (float) $row[5],
                ]
            );
            $written++;
            $minTs = $minTs === null || $openTs->lt($minTs) ? $openTs : $minTs;
            $maxTs = $maxTs === null || $openTs->gt($maxTs) ? $openTs : $maxTs;
        }

        if ($written > 0 && $minTs && $maxTs) {
            $gaps = $this->detectGaps($exchange, $normalizedSymbol, $interval, $minTs, $maxTs);
            if (! empty($gaps)) {
                Log::warning('[MarketOhlcBackfillService] gap detected in candle sequence after backfill', [
                    'exchange' => $exchange,
                    'symbol' => $normalizedSymbol,
                    'timeframe' => $interval,
                    'gaps' => $gaps,
                ]);
            }
        }

        return $written;
    }

    /**
     * Scan stored candles for the given (exchange, symbol, timeframe) --
     * optionally bounded to a [$from, $to] window -- and return any gaps
     * where consecutive candles are further apart than the timeframe's
     * expected interval (with a 1.5x tolerance to avoid false positives
     * from Binance's own occasional millisecond jitter). Never throws --
     * a genuine no-trades window on a real exchange is rare but possible
     * and must be reported, not treated as a crash condition.
     *
     * @return array<int, array{from: string, to: string, gap_seconds: int, missing_candles: int}>
     */
    public function detectGaps(string $exchange, string $symbol, string $timeframe, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $interval = $this->normalizeInterval($timeframe);
        $expectedSeconds = self::intervalSeconds($interval);

        $query = MarketOhlc::query()
            ->where('exchange', $exchange)
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', $interval)
            ->orderBy('ts');

        if ($from) {
            $query->where('ts', '>=', $from);
        }
        if ($to) {
            $query->where('ts', '<=', $to);
        }

        $timestamps = $query->pluck('ts');
        if ($timestamps->count() < 2) {
            return [];
        }

        $toleranceSeconds = (int) round($expectedSeconds * 1.5);
        $gaps = [];
        $previous = null;
        foreach ($timestamps as $ts) {
            $current = $ts instanceof Carbon ? $ts : Carbon::parse((string) $ts, 'UTC');
            if ($previous !== null) {
                $diffSeconds = $previous->diffInSeconds($current);
                if ($diffSeconds > $toleranceSeconds) {
                    $gaps[] = [
                        'from' => $previous->toIso8601String(),
                        'to' => $current->toIso8601String(),
                        'gap_seconds' => $diffSeconds,
                        'missing_candles' => intdiv($diffSeconds, $expectedSeconds) - 1,
                    ];
                }
            }
            $previous = $current;
        }

        return $gaps;
    }

    private static function intervalSeconds(string $interval): int
    {
        return match ($interval) {
            '1m' => 60, '3m' => 180, '5m' => 300, '15m' => 900, '30m' => 1800,
            '1h' => 3600, '2h' => 7200, '4h' => 14400, '6h' => 21600, '8h' => 28800, '12h' => 43200,
            '1d' => 86400, '3d' => 259200, '1w' => 604800, '1M' => 2592000,
            default => 60,
        };
    }

    public function backfillForConfig(RatioBotConfig $config, int $limit = self::DEFAULT_BACKFILL_LIMIT): int
    {
        return $this->backfill(
            $config->exchangeKey(),
            $config->normalizedSymbol(),
            $config->timeFrame(),
            $limit,
            $config->marketType() === 'futures'
        );
    }

    private function normalizeInterval(string $timeframe): string
    {
        $normalized = strtolower(trim($timeframe));

        return in_array($normalized, self::VALID_INTERVALS, true) ? $normalized : '1m';
    }
}
