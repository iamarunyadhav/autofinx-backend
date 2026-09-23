<?php

namespace App\Domain\Trading;

use Carbon\CarbonImmutable;

/**
 * Part B -- "decisions come from CLOSED candles only".
 *
 * The candle arrays this codebase passes around (TradingMarketService::candles(),
 * LiveCandleCacheService) carry the bar's OPEN time as `timestamp` (ms) /
 * `opens_at` (ISO). There is no `x: true` closed flag on them the way the raw
 * Binance kline websocket frame has, so "is this bar closed" is derived the
 * only honest way available here: openTime + timeframe <= now.
 *
 * Deliberately a separate class rather than logic inside
 * TradeForecastService::forecast(): forecast() is shared with Ratio Bot,
 * Robot Trade and Future Trade, and this task is explicitly not allowed to
 * change their behaviour. Quick Trade's own call sites opt in by calling
 * this first and handing forecast() the already-trimmed candles plus the
 * resulting timing metadata.
 */
class CandleCloseResolver
{
    public function __construct(private readonly TimeframeRegistry $timeframes)
    {
    }

    /**
     * @param  array<int, array<string, mixed>>  $candles  oldest-first, as produced by TradingMarketService::candles()
     * @return array{
     *   candles: array<int, array<string, mixed>>,
     *   include_forming_bar: bool,
     *   forming_bar_dropped: bool,
     *   timestamps_available: bool,
     *   decision_candle_close_at: string|null,
     *   decision_candle_close_ms: int|null,
     *   seconds_since_last_close: float|null,
     *   next_close_in_seconds: float|null,
     *   grace_ms: int,
     *   within_grace: bool,
     *   candle_gap: bool,
     *   timeframe_seconds: int
     * }
     */
    public function resolve(array $candles, string $timeframe, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $nowMs = $now->getTimestampMs();
        $tfSeconds = max(1, $this->timeframes->seconds($timeframe));
        $tfMs = $tfSeconds * 1000;
        $graceMs = max(0, (int) config('quick_trade.decision_close_grace_ms', 1500));
        $enabled = (bool) config('quick_trade.closed_candles_only', true);

        $base = [
            'candles' => $candles,
            'include_forming_bar' => true,
            'forming_bar_dropped' => false,
            'timestamps_available' => false,
            'decision_candle_close_at' => null,
            'decision_candle_close_ms' => null,
            'seconds_since_last_close' => null,
            'next_close_in_seconds' => null,
            'grace_ms' => $graceMs,
            'within_grace' => false,
            'candle_gap' => false,
            'timeframe_seconds' => $tfSeconds,
        ];

        if ($candles === []) {
            return $base;
        }

        // Honest fallback: if the bars carry no usable open timestamp we
        // cannot tell a closed bar from a forming one, so we say so rather
        // than trimming an arbitrary last element.
        $timestamps = array_map(fn ($candle) => $this->openMs($candle), $candles);
        if (in_array(null, $timestamps, true)) {
            return $base;
        }
        $base['timestamps_available'] = true;

        $closed = [];
        $dropped = false;
        foreach ($candles as $index => $candle) {
            $closeMs = (int) $timestamps[$index] + $tfMs;
            if ($closeMs <= $nowMs) {
                $closed[] = $candle;
            } else {
                $dropped = true;
            }
        }

        if ($closed === []) {
            // Nothing is closed yet (very short buffer). Keep the input
            // rather than returning an empty decision input -- forecast()
            // itself already refuses to decide on <10 candles.
            return $base;
        }

        $lastClosedOpenMs = (int) $this->openMs($closed[count($closed) - 1]);
        $lastCloseMs = $lastClosedOpenMs + $tfMs;
        $sinceCloseMs = $nowMs - $lastCloseMs;
        $gapMultiplier = max(1.0, (float) config('quick_trade.candle_gap_timeframe_multiplier', 1.5));

        $base['candles'] = $enabled ? $closed : $candles;
        $base['include_forming_bar'] = $enabled ? false : true;
        $base['forming_bar_dropped'] = $enabled && $dropped;
        $base['decision_candle_close_at'] = CarbonImmutable::createFromTimestampMs($lastCloseMs)->toIso8601String();
        $base['decision_candle_close_ms'] = $lastCloseMs;
        $base['seconds_since_last_close'] = round($sinceCloseMs / 1000, 3);
        $base['next_close_in_seconds'] = round(max(0, ($lastCloseMs + $tfMs) - $nowMs) / 1000, 3);
        $base['within_grace'] = $sinceCloseMs >= 0 && $sinceCloseMs < $graceMs;
        // B4: the last CLOSED candle is older than 1.5x the timeframe, i.e.
        // at least one whole bar is missing from the feed.
        $base['candle_gap'] = $sinceCloseMs > ($tfMs * $gapMultiplier);

        return $base;
    }

    /**
     * Cache key for the close-anchored decision cache (B2): one entry per
     * symbol/timeframe/horizon per CLOSED CANDLE, so the decision is stable
     * within a candle and genuinely recomputed after the next close.
     */
    public function decisionCacheKey(string $symbol, string $timeframe, int $horizonSeconds, ?int $decisionCandleCloseMs): ?string
    {
        if ($decisionCandleCloseMs === null || trim($symbol) === '') {
            return null;
        }

        return sprintf(
            'quick_trade:decision:%s:%s:%d:%d',
            strtoupper($symbol),
            strtolower($timeframe),
            $horizonSeconds,
            $decisionCandleCloseMs,
        );
    }

    private function openMs(array $candle): ?int
    {
        foreach (['timestamp', 'time', 'open_time'] as $key) {
            if (isset($candle[$key]) && is_numeric($candle[$key]) && (float) $candle[$key] > 0) {
                $value = (float) $candle[$key];

                // Tolerate seconds-precision timestamps as well as ms.
                return (int) ($value < 1e11 ? $value * 1000 : $value);
            }
        }

        if (! empty($candle['opens_at'])) {
            try {
                return CarbonImmutable::parse((string) $candle['opens_at'])->getTimestampMs();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }
}
