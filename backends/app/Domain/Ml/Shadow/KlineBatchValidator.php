<?php

namespace App\Domain\Ml\Shadow;

/**
 * Pure validator for one page of Binance Futures klines.
 *
 * Only CLOSED, aligned, internally consistent candles are accepted. Every
 * other row is either excluded (still-forming bar — expected on every live
 * poll, so counted but not stored as a rejection) or rejected with an
 * explicit reason. Accepted rows are the only rows that may ever reach
 * ml_candles, and therefore the only rows that can become training data.
 *
 * Reasons: duplicate, invalid_ohlcv, missing_interval, out_of_order,
 * symbol_timeframe_mismatch.
 */
final class KlineBatchValidator
{
    public const REASON_DUPLICATE = 'duplicate';

    public const REASON_INVALID_OHLCV = 'invalid_ohlcv';

    public const REASON_MISSING_INTERVAL = 'missing_interval';

    public const REASON_OUT_OF_ORDER = 'out_of_order';

    public const REASON_MISMATCH = 'symbol_timeframe_mismatch';

    // A 5m futures candle opening >20% away from the previous close cannot be
    // the same instrument; it is treated as a wrong-series row, not a move.
    private const MAX_OPEN_GAP_RATIO = 0.20;

    // Upper bound on individual missing-interval rows recorded for one gap so a
    // multi-day exchange outage cannot flood the rejection table.
    private const MAX_MISSING_ROWS_PER_GAP = 300;

    /**
     * @param  array<int, mixed>  $rows  Raw Binance kline arrays.
     * @param  array{open_time_ms:int, close:float}|null  $previous  Last stored candle before this page.
     * @return array{accepted: list<array<string, int|float>>, rejections: list<array{open_time_ms:int, reason:string, payload:array<string, mixed>}>, forming_excluded:int}
     */
    public function validate(array $rows, int $intervalMs, int $nowMs, ?array $previous = null): array
    {
        $accepted = [];
        $rejections = [];
        $forming = 0;
        $last = $previous;

        foreach ($rows as $row) {
            if (! is_array($row) || count($row) < 7) {
                $rejections[] = ['open_time_ms' => 0, 'reason' => self::REASON_INVALID_OHLCV, 'payload' => ['row' => $row, 'detail' => 'malformed_row']];

                continue;
            }

            $openMs = $this->intOrNull($row[0]);
            $closeMs = $this->intOrNull($row[6]);
            if ($openMs === null || $closeMs === null) {
                $rejections[] = ['open_time_ms' => 0, 'reason' => self::REASON_INVALID_OHLCV, 'payload' => ['row' => $row, 'detail' => 'non_integer_time']];

                continue;
            }

            // Closed-candle guard: Binance's close_time is open + interval - 1.
            // A bar is closed only once that instant is strictly in the past.
            if ($closeMs >= $nowMs) {
                $forming++;

                continue;
            }

            if ($openMs % $intervalMs !== 0 || ($closeMs - $openMs + 1) !== $intervalMs) {
                $rejections[] = ['open_time_ms' => max(0, $openMs), 'reason' => self::REASON_MISMATCH, 'payload' => ['row' => $row, 'detail' => 'interval_or_alignment_mismatch', 'expected_interval_ms' => $intervalMs]];

                continue;
            }

            $candle = $this->ohlcv($row);
            if ($candle === null) {
                $rejections[] = ['open_time_ms' => $openMs, 'reason' => self::REASON_INVALID_OHLCV, 'payload' => ['row' => $row]];

                continue;
            }

            if ($last !== null) {
                if ($openMs === $last['open_time_ms']) {
                    $rejections[] = ['open_time_ms' => $openMs, 'reason' => self::REASON_DUPLICATE, 'payload' => ['row' => $row, 'detail' => 'repeated_open_time_in_sequence']];

                    continue;
                }
                if ($openMs < $last['open_time_ms']) {
                    $rejections[] = ['open_time_ms' => $openMs, 'reason' => self::REASON_OUT_OF_ORDER, 'payload' => ['row' => $row, 'previous_open_time_ms' => $last['open_time_ms']]];

                    continue;
                }
                if ($last['close'] > 0 && abs($candle['open'] / $last['close'] - 1) > self::MAX_OPEN_GAP_RATIO) {
                    $rejections[] = ['open_time_ms' => $openMs, 'reason' => self::REASON_MISMATCH, 'payload' => ['row' => $row, 'detail' => 'price_discontinuity', 'previous_close' => $last['close']]];

                    continue;
                }
                $expected = $last['open_time_ms'] + $intervalMs;
                $recorded = 0;
                for ($missing = $expected; $missing < $openMs && $recorded < self::MAX_MISSING_ROWS_PER_GAP; $missing += $intervalMs) {
                    $rejections[] = ['open_time_ms' => $missing, 'reason' => self::REASON_MISSING_INTERVAL, 'payload' => ['next_present_open_time_ms' => $openMs]];
                    $recorded++;
                }
            }

            $accepted[] = ['open_time_ms' => $openMs, 'close_time_ms' => $closeMs] + $candle;
            $last = ['open_time_ms' => $openMs, 'close' => $candle['close']];
        }

        return ['accepted' => $accepted, 'rejections' => $rejections, 'forming_excluded' => $forming];
    }

    /** @return array{open:float, high:float, low:float, close:float, volume:float, taker_buy_volume:?float, quote_volume:?float, trade_count:?int}|null */
    private function ohlcv(array $row): ?array
    {
        foreach ([1, 2, 3, 4, 5] as $index) {
            if (! is_numeric($row[$index] ?? null)) {
                return null;
            }
        }
        $open = (float) $row[1];
        $high = (float) $row[2];
        $low = (float) $row[3];
        $close = (float) $row[4];
        $volume = (float) $row[5];
        foreach ([$open, $high, $low, $close, $volume] as $value) {
            if (! is_finite($value)) {
                return null;
            }
        }
        if ($open <= 0 || $close <= 0 || $low <= 0 || $volume < 0 || $high < max($open, $close) || $low > min($open, $close) || $high < $low) {
            return null;
        }
        // Field 9 = taker BUY base volume. When present it must be a part of
        // total volume; an impossible value means a corrupt row, not "unknown".
        $takerBuy = null;
        if (array_key_exists(9, $row) && $row[9] !== null) {
            if (! is_numeric($row[9]) || ! is_finite((float) $row[9])) {
                return null;
            }
            $takerBuy = (float) $row[9];
            if ($takerBuy < 0 || $takerBuy > $volume * (1 + 1e-9) + 1e-12) {
                return null;
            }
        }

        return [
            'open' => $open, 'high' => $high, 'low' => $low, 'close' => $close, 'volume' => $volume,
            'taker_buy_volume' => $takerBuy,
            'quote_volume' => is_numeric($row[7] ?? null) ? (float) $row[7] : null,
            'trade_count' => is_numeric($row[8] ?? null) ? (int) $row[8] : null,
        ];
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }
}
