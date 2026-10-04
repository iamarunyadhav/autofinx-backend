<?php

namespace App\Domain\Ml\Shadow;

/**
 * How much history (candles + funding) each symbol collects (config ml.history).
 * One place, so the candle backfill and the funding sync always cover the
 * same span; a label older than its funding history would silently count
 * funding as zero.
 */
final class MlHistoryWindow
{
    public static function months(string $symbol): int
    {
        $bySymbol = (array) config('ml.history.months_by_symbol', []);

        return max(1, (int) ($bySymbol[strtoupper($symbol)] ?? config('ml.history.default_months', 36)));
    }

    /** Day-aligned, so re-runs on the same day resume the same checkpoint. */
    public static function startMs(string $symbol): int
    {
        return now('UTC')->subMonthsNoOverflow(self::months($symbol))->startOfDay()->getTimestampMs();
    }
}
