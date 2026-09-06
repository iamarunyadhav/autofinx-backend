<?php

namespace App\Domain\Market;

use App\Domain\Venue\OandaClient;
use App\Models\MarketOhlc;
use App\Models\RatioBotConfig;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Forex/metals sibling of MarketOhlcBackfillService (which is hardcoded to
 * Binance's /api/v3/klines) -- hits OANDA's real, documented candles
 * endpoint instead. Writes into the SAME `market_ohlc` table, keyed by the
 * same (exchange, symbol, timeframe, ts) unique constraint, with
 * `exchange='oanda'` -- no schema change, matches the existing table shape
 * exactly.
 *
 * Built without a live OANDA account (see OandaClient's own docblock) --
 * the request shape follows OANDA's real public API docs
 * (GET /v3/instruments/{name}/candles) but has not been run against a real
 * account.
 */
class OandaOhlcBackfillService
{
    public const DEFAULT_BACKFILL_LIMIT = 500;

    private const GRANULARITY_MAP = [
        '1m' => 'M1', '2m' => 'M2', '4m' => 'M4', '5m' => 'M5', '10m' => 'M10',
        '15m' => 'M15', '30m' => 'M30', '1h' => 'H1', '2h' => 'H2', '3h' => 'H3',
        '4h' => 'H4', '6h' => 'H6', '8h' => 'H8', '12h' => 'H12', '1d' => 'D',
    ];

    public function needsBackfill(string $exchange, string $symbol, string $timeframe, int $minRows = MarketOhlcBackfillService::MIN_ROWS_THRESHOLD): bool
    {
        return MarketOhlc::query()
            ->where('exchange', $exchange)
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtolower(trim($timeframe)))
            ->count() < $minRows;
    }

    public function needsBackfillForConfig(RatioBotConfig $config, int $minRows = MarketOhlcBackfillService::MIN_ROWS_THRESHOLD): bool
    {
        return $this->needsBackfill($config->exchangeKey(), $config->normalizedSymbol(), $config->timeFrame(), $minRows);
    }

    /**
     * @param  string  $apiUrl  OANDA REST host, e.g. https://api-fxpractice.oanda.com
     */
    public function backfill(string $apiUrl, string $apiKey, string $exchange, string $symbol, string $timeframe, int $limit = self::DEFAULT_BACKFILL_LIMIT): int
    {
        $normalizedSymbol = strtoupper(trim($symbol));
        $granularity = $this->normalizeGranularity($timeframe);
        $limit = max(30, min(5000, $limit));
        $instrument = (new OandaClient($apiUrl, $apiKey, ''))->toInstrument($normalizedSymbol);

        $response = Http::withHeaders(array_filter([
            'Authorization' => $apiKey !== '' ? 'Bearer ' . $apiKey : null,
        ]))->timeout(15)->get(rtrim($apiUrl, '/') . "/v3/instruments/{$instrument}/candles", [
            'granularity' => $granularity,
            'count' => $limit,
            'price' => 'M', // mid prices -- matches what getLatestPrice()'s mid-of-bid/ask already surfaces elsewhere
        ]);

        if ($response->failed()) {
            throw new RuntimeException("OANDA candles backfill failed for {$normalizedSymbol} {$granularity}: " . $response->body());
        }

        $written = 0;
        foreach ((array) $response->json('candles') as $row) {
            if (! is_array($row) || ! isset($row['mid'])) {
                continue;
            }

            $openTs = Carbon::parse((string) $row['time']);
            $mid = (array) $row['mid'];
            $existingId = MarketOhlc::query()
                ->where('exchange', $exchange)
                ->where('symbol', $normalizedSymbol)
                ->where('timeframe', strtolower($timeframe))
                ->where('ts', $openTs)
                ->value('id');

            MarketOhlc::updateOrCreate(
                ['exchange' => $exchange, 'symbol' => $normalizedSymbol, 'timeframe' => strtolower($timeframe), 'ts' => $openTs],
                [
                    'id' => $existingId ?: (string) Str::uuid(),
                    'open' => (float) $mid['o'],
                    'high' => (float) $mid['h'],
                    'low' => (float) $mid['l'],
                    'close' => (float) $mid['c'],
                    'volume' => (float) ($row['volume'] ?? 0),
                ]
            );
            $written++;
        }

        return $written;
    }

    public function backfillForConfig(RatioBotConfig $config, string $apiUrl, string $apiKey, int $limit = self::DEFAULT_BACKFILL_LIMIT): int
    {
        return $this->backfill($apiUrl, $apiKey, $config->exchangeKey(), $config->normalizedSymbol(), $config->timeFrame(), $limit);
    }

    private function normalizeGranularity(string $timeframe): string
    {
        return self::GRANULARITY_MAP[strtolower(trim($timeframe))] ?? 'M1';
    }
}
