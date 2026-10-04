<?php

namespace App\Domain\Ml\Shadow;

use App\Models\MlFundingRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Funding cost for holding a Binance USDT-M perpetual over [T, T+h].
 *
 * Sign convention (per unit notional, in percent): a LONG pays positive
 * funding and receives negative funding; a SHORT is the exact opposite. So
 * funding_cost_buy_pct = +Σ rate × 100 and funding_cost_sell_pct = −that.
 * A funding event at time f is paid by a position that is open at f; a
 * position opened at T and closed at T+h is open for T < f <= T+h.
 */
class FundingRateService
{
    // Sanity bound: Binance caps funding far below this; anything bigger is a bad row.
    private const MAX_ABS_RATE = 0.05;

    public function __construct(private readonly BinanceFuturesKlineClient $client) {}

    /**
     * Idempotent, resumable sync: always continues after the newest stored
     * funding time (or from $fromMs when nothing is stored yet).
     *
     * @return array{written:int, pages:int, rejected:int}
     */
    public function sync(string $symbol, ?int $fromMs = null, int $maxPages = 50): array
    {
        $symbol = strtoupper($symbol);
        $latest = MlFundingRate::query()->where('symbol', $symbol)->max('funding_time_ms');
        $earliest = MlFundingRate::query()->where('symbol', $symbol)->min('funding_time_ms');
        $start = $fromMs ?? MlHistoryWindow::startMs($symbol);
        // Continue after the newest row, unless the stored history starts
        // later than the symbol's history window (e.g. after it was extended
        // from 12 months to 5 years): then re-scan from the start. Rows are
        // insertOrIgnore'd, and 5 years of 8h funding is only ~6 pages.
        // A symbol listed after $start has nothing older: remember where
        // Binance's history really begins so that is not re-scanned every run.
        $listedFromKey = 'ml:funding:listed-from:'.$symbol;
        $intervalMs = (int) config('ml.funding.interval_hours', 8) * 3_600_000;
        $covered = $earliest !== null && (int) $earliest <= max($start, (int) Cache::get($listedFromKey, 0)) + $intervalMs;
        $rescan = ! ($latest !== null && $covered);
        $cursor = $rescan ? $start : (int) $latest + 1;
        $result = ['written' => 0, 'pages' => 0, 'rejected' => 0];

        while ($result['pages'] < $maxPages) {
            $rows = $this->client->fundingRates($symbol, $cursor);
            $result['pages']++;
            $now = now('UTC');
            $inserts = [];
            $maxTime = null;
            foreach ($rows as $row) {
                $time = is_array($row) && is_numeric($row['fundingTime'] ?? null) ? (int) $row['fundingTime'] : null;
                $rate = is_array($row) && is_numeric($row['fundingRate'] ?? null) ? (float) $row['fundingRate'] : null;
                if ($time === null || $rate === null || ! is_finite($rate) || abs($rate) > self::MAX_ABS_RATE
                    || strtoupper((string) ($row['symbol'] ?? $symbol)) !== $symbol) {
                    $result['rejected']++;

                    continue;
                }
                $maxTime = max($maxTime ?? 0, $time);
                $inserts[] = [
                    'symbol' => $symbol, 'funding_time_ms' => $time, 'funding_rate' => $rate,
                    'mark_price' => is_numeric($row['markPrice'] ?? null) && (float) $row['markPrice'] > 0 ? (float) $row['markPrice'] : null,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            foreach (array_chunk($inserts, 500) as $chunk) {
                $result['written'] += DB::table('ml_funding_rates')->insertOrIgnore($chunk);
            }
            if ($maxTime === null || count($rows) < 1000) {
                break;
            }
            $cursor = $maxTime + 1;
        }
        if ($rescan && ($first = MlFundingRate::query()->where('symbol', $symbol)->min('funding_time_ms')) !== null) {
            Cache::forever($listedFromKey, (int) $first);
        }

        return $result;
    }

    /**
     * Actual (historical) BUY-side funding cost in percent for each anchor.
     *
     * @param  list<int>  $anchorsMs
     * @return array<int, float> anchor => funding_cost_buy_pct (SELL = −value)
     */
    public function buyCostPct(string $symbol, array $anchorsMs, int $horizonMs): array
    {
        if ($anchorsMs === []) {
            return [];
        }
        $events = MlFundingRate::query()->where('symbol', strtoupper($symbol))
            ->whereBetween('funding_time_ms', [min($anchorsMs), max($anchorsMs) + $horizonMs])
            ->orderBy('funding_time_ms')->get(['funding_time_ms', 'funding_rate']);
        $times = $events->pluck('funding_time_ms')->map(fn ($v) => (int) $v)->all();
        // prefix[i] = Σ rate of events[0..i-1]
        $prefix = [0.0];
        foreach ($events as $event) {
            $prefix[] = end($prefix) + (float) $event->funding_rate;
        }

        $out = [];
        foreach ($anchorsMs as $anchor) {
            // events with T < f <= T+h
            $from = self::firstGreater($times, $anchor);
            $to = self::firstGreater($times, $anchor + $horizonMs);
            $out[$anchor] = round(($prefix[$to] - $prefix[$from]) * 100, 10);
        }

        return $out;
    }

    /**
     * Live estimate when the future rates are unknown: the number of funding
     * settlements inside (T, T+h] × the latest known rate.
     */
    public function estimateBuyCostPct(string $symbol, int $anchorMs, int $horizonMs): float
    {
        $latest = MlFundingRate::query()->where('symbol', strtoupper($symbol))
            ->where('funding_time_ms', '<=', $anchorMs)->orderByDesc('funding_time_ms')->value('funding_rate');
        if ($latest === null) {
            return 0.0;
        }

        return round(self::settlementsInWindow($anchorMs, $horizonMs) * (float) $latest * 100, 10);
    }

    /** Funding settlements on the UTC 8h grid with T < f <= T+h. */
    public static function settlementsInWindow(int $anchorMs, int $horizonMs): int
    {
        $interval = (int) config('ml.funding.interval_hours', 8) * 3_600_000;

        return intdiv($anchorMs + $horizonMs, $interval) - intdiv($anchorMs, $interval);
    }

    /** Index of the first element strictly greater than $value (binary search). */
    private static function firstGreater(array $sorted, int $value): int
    {
        $lo = 0;
        $hi = count($sorted);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($sorted[$mid] <= $value) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }
}
