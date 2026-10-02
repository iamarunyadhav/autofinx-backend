<?php

namespace App\Domain\Ml\Shadow;

use App\Models\MlBackfillCheckpoint;
use App\Models\MlCandleRejection;
use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * Historical backfill (paginated, checkpointed, resumable) and live sync of
 * closed Binance Futures candles into ml_candles. Both paths share one
 * validator and one idempotent store, so re-running either is always safe.
 */
class MlCandleCollector
{
    public const SOURCE_BACKFILL = 'binance_futures_rest_backfill';

    public const SOURCE_SYNC = 'binance_futures_rest_sync';

    private const INTERVAL_MS = ['1m' => 60_000, '5m' => 300_000, '15m' => 900_000, '1h' => 3_600_000, '4h' => 14_400_000];

    public function __construct(
        private readonly BinanceFuturesKlineClient $client,
        private readonly KlineBatchValidator $validator,
        private readonly MlCandleStore $store,
    ) {}

    public static function intervalMs(string $timeframe): int
    {
        return self::INTERVAL_MS[$timeframe] ?? throw new InvalidArgumentException("Unsupported ML timeframe: {$timeframe}");
    }

    /** Open time of the most recent candle that is fully closed at $nowMs. */
    public static function lastClosedOpenMs(int $nowMs, int $intervalMs): int
    {
        return intdiv($nowMs, $intervalMs) * $intervalMs - $intervalMs;
    }

    /**
     * @param  (Closure(MlBackfillCheckpoint):void)|null  $progress
     */
    public function backfill(string $symbol, string $timeframe, int $fromMs, int $toMs, ?int $maxPages = null, ?Closure $progress = null, bool $restart = false): MlBackfillCheckpoint
    {
        $symbol = $this->assertSymbol($symbol);
        $interval = self::intervalMs($timeframe);
        $fromMs = intdiv($fromMs, $interval) * $interval;
        $toMs = min($toMs, self::lastClosedOpenMs($this->nowMs(), $interval));
        if ($toMs < $fromMs) {
            throw new InvalidArgumentException('Backfill range ends before it starts (or is entirely in the future).');
        }

        $checkpoint = MlBackfillCheckpoint::query()->firstOrCreate([
            'provider' => (string) config('ml.provider', 'binance_futures'), 'symbol' => $symbol,
            'timeframe' => $timeframe, 'range_from_ms' => $fromMs,
        ], ['range_to_ms' => $toMs, 'cursor_ms' => $fromMs, 'status' => 'RUNNING']);

        // A deliberate re-scan (e.g. to enrich rows with a newly stored field)
        // rewinds the cursor. Safe: the store never overwrites a stored closed
        // candle's OHLCV, it only fills fields that were null.
        if ($restart) {
            $checkpoint->forceFill(['cursor_ms' => $fromMs, 'pages' => 0, 'rows_written' => 0, 'rows_rejected' => 0, 'status' => 'RUNNING']);
        }

        // Re-running with a later end extends the same checkpoint; the cursor
        // is never moved backwards, so completed pages are never re-fetched.
        $checkpoint->forceFill([
            'range_to_ms' => max((int) $checkpoint->range_to_ms, $toMs),
            'status' => (int) $checkpoint->cursor_ms > max((int) $checkpoint->range_to_ms, $toMs) ? 'COMPLETED' : 'RUNNING',
            'last_error' => null,
        ])->save();

        $limit = (int) config('ml.binance.page_limit', 1000);
        $maxPages = $maxPages ?? (int) config('ml.backfill.max_pages_per_run', 400);
        $sleepMs = (int) config('ml.binance.backfill_page_sleep_ms', 750);
        $pages = 0;

        try {
            while ($checkpoint->status === 'RUNNING' && $pages < $maxPages) {
                $cursor = (int) $checkpoint->cursor_ms;
                $end = min((int) $checkpoint->range_to_ms + $interval - 1, $cursor + ($limit * $interval) - 1);
                $outcome = $this->fetchAndStore($symbol, $timeframe, $cursor, $end, $limit, self::SOURCE_BACKFILL);
                $pages++;

                $next = $outcome['max_open_ms'] !== null ? $outcome['max_open_ms'] + $interval : $end + 1;
                $checkpoint->forceFill([
                    'cursor_ms' => max($next, $cursor + $interval),
                    'pages' => $checkpoint->pages + 1,
                    'rows_written' => $checkpoint->rows_written + $outcome['written'],
                    'rows_rejected' => $checkpoint->rows_rejected + $outcome['rejected'],
                ]);
                if ((int) $checkpoint->cursor_ms > (int) $checkpoint->range_to_ms) {
                    $checkpoint->status = 'COMPLETED';
                }
                $checkpoint->save();
                if ($progress) {
                    $progress($checkpoint);
                }
                if ($checkpoint->status === 'RUNNING' && $sleepMs > 0) {
                    $this->pause($sleepMs);
                }
            }
        } catch (Throwable $exception) {
            // The cursor only advances after a page is stored, so the next run
            // resumes at exactly the failed page.
            $checkpoint->forceFill(['status' => 'FAILED', 'last_error' => mb_substr($exception->getMessage(), 0, 2000)])->save();
            throw $exception;
        }

        return $checkpoint->refresh();
    }

    /**
     * Live sync: everything closed since the last stored candle, then a
     * bounded retry of recent unresolved missing intervals.
     *
     * @return array{written:int, unchanged:int, rejected:int, forming_excluded:int, gaps_retried:int}
     */
    public function sync(string $symbol, string $timeframe, int $maxPages = 3): array
    {
        $symbol = $this->assertSymbol($symbol);
        $interval = self::intervalMs($timeframe);
        $limit = (int) config('ml.binance.page_limit', 1000);
        $lastClosed = self::lastClosedOpenMs($this->nowMs(), $interval);
        $latest = $this->store->latestOpenTime($symbol, $timeframe);
        $cursor = $latest !== null ? $latest + $interval : $lastClosed - (($limit - 1) * $interval);
        $totals = ['written' => 0, 'unchanged' => 0, 'rejected' => 0, 'forming_excluded' => 0, 'gaps_retried' => 0];

        for ($page = 0; $page < $maxPages && $cursor <= $lastClosed; $page++) {
            // Include the forming candle's window on purpose: the validator
            // must see and exclude it rather than relying on the range alone.
            $end = min($lastClosed + (2 * $interval) - 1, $cursor + ($limit * $interval) - 1);
            $outcome = $this->fetchAndStore($symbol, $timeframe, $cursor, $end, $limit, self::SOURCE_SYNC);
            foreach (['written', 'unchanged', 'rejected', 'forming_excluded'] as $key) {
                $totals[$key] += $outcome[$key];
            }
            $cursor = $outcome['max_open_ms'] !== null ? $outcome['max_open_ms'] + $interval : $end + 1;
        }

        $gaps = MlCandleRejection::query()
            ->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('reason', KlineBatchValidator::REASON_MISSING_INTERVAL)->where('resolved', false)
            ->where('open_time_ms', '>=', $this->nowMs() - (7 * 86_400_000))
            ->where('occurrences', '<', 5)
            ->orderByDesc('open_time_ms')->limit(10)->pluck('open_time_ms');
        foreach ($gaps as $gapOpenMs) {
            $this->fetchAndStore($symbol, $timeframe, (int) $gapOpenMs, (int) $gapOpenMs + $interval - 1, 1, self::SOURCE_SYNC);
            $totals['gaps_retried']++;
        }

        return $totals;
    }

    /** @return array{written:int, unchanged:int, rejected:int, forming_excluded:int, max_open_ms:?int} */
    private function fetchAndStore(string $symbol, string $timeframe, int $startMs, int $endMs, int $limit, string $source): array
    {
        $interval = self::intervalMs($timeframe);
        $rows = $this->client->klines($symbol, $timeframe, $startMs, $endMs, $limit);
        $batch = $this->validator->validate($rows, $interval, $this->nowMs(), $this->store->previousCandle($symbol, $timeframe, $startMs));
        $stored = $this->store->persist($symbol, $timeframe, $batch, $source);

        $openTimes = array_filter(array_map(
            static fn (mixed $row): ?int => is_array($row) && is_numeric($row[0] ?? null) ? (int) $row[0] : null,
            $rows,
        ), static fn (?int $value): bool => $value !== null);
        // Never advance past a forming candle: it must be re-read once closed.
        $closedOpenTimes = array_filter($openTimes, fn (int $open): bool => $open <= self::lastClosedOpenMs($this->nowMs(), $interval));

        return $stored + ['max_open_ms' => $closedOpenTimes === [] ? null : max($closedOpenTimes)];
    }

    private function assertSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (! in_array($symbol, (array) config('ml.symbols', []), true)) {
            throw new InvalidArgumentException("Symbol {$symbol} is not configured for ML shadow learning (ml.symbols).");
        }

        return $symbol;
    }

    protected function nowMs(): int
    {
        return now('UTC')->getTimestampMs();
    }

    protected function pause(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
