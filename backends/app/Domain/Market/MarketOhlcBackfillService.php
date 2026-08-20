<?php

namespace App\Domain\Market;

use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
        foreach ((array) $response->json() as $row) {
            if (! is_array($row) || count($row) < 6) {
                continue;
            }

            $openTs = Carbon::createFromTimestampMs((int) $row[0]);
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
        }

        return $written;
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
