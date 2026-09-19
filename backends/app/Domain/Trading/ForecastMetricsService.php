<?php

namespace App\Domain\Trading;

use Illuminate\Support\Collection;

/** Canonical, side-effect-free metrics for live research and backtests. */
class ForecastMetricsService
{
    public const MIN_TRUSTED_SAMPLES = 30;

    /**
     * Additive (holdout-validation task, metric #2): max drawdown needs a
     * full chronological P&L sequence, not just a count of rows, to mean
     * anything -- a 2-point "drawdown" is not informative even if 2 >= some
     * arbitrary floor. This is a SEPARATE, smaller, reasoned floor from
     * MIN_TRUSTED_SAMPLES (30, used for rate/average statistics where
     * sampling noise is the concern): 5 is the smallest sequence length
     * where "peak, then a real decline, then recovery or not" can be
     * observed at all rather than just reflecting 1-2 trade outcomes.
     */
    public const MIN_DRAWDOWN_SEQUENCE_POINTS = 5;

    public function summarize(Collection|array $rows): array
    {
        $rows = collect($rows);
        $evaluated = $rows->filter(fn ($row) => data_get($row, 'primary_comparison.direction_correct') !== null);
        $hits = $evaluated->where('primary_comparison.direction_correct', true)->count();
        $ranges = $evaluated->filter(fn ($row) => data_get($row, 'primary_comparison.price_in_predicted_range') !== null);
        $rangeHits = $ranges->where('primary_comparison.price_in_predicted_range', true)->count();
        $errors = $evaluated->map(fn ($row) => abs((float) data_get($row, 'primary_comparison.price_error', 0)))->filter();
        $netRows = $rows->filter(fn ($row) => data_get($row, 'net_pnl_booked') !== null);
        $netPnl = (float) $netRows->sum(fn ($row) => (float) data_get($row, 'net_pnl_booked', 0));
        $directionRows = $rows->filter(fn ($row) => data_get($row, 'primary_comparison.predicted_direction') !== null);
        $directionBreakdown = [];
        foreach (['buy', 'sell', 'neutral'] as $direction) {
            $bucket = $directionRows->filter(fn ($row) => data_get($row, 'primary_comparison.predicted_direction') === $direction);
            $bucketEvaluated = $bucket->filter(fn ($row) => data_get($row, 'primary_comparison.direction_correct') !== null);
            $bucketHits = $bucketEvaluated->filter(fn ($row) => data_get($row, 'primary_comparison.direction_correct') === true)->count();
            $directionBreakdown[$direction] = [
                'predictions' => $bucket->count(), 'evaluated' => $bucketEvaluated->count(),
                'accuracy_pct' => $this->trusted($bucketEvaluated->count()) ? round($bucketHits / $bucketEvaluated->count() * 100, 2) : null,
                // Additive: 'neutral' is this codebase's real field value for
                // a non-directional (WAIT) call -- see
                // TradeForecastService::forecast()'s 'decision_trend'
                // (BUY/SELL/WAIT) and QuickTradeResearchService::comparison()
                // (buy/sell/neutral, lowercase). How OFTEN the forecast
                // landed in this bucket at all, out of every directionally-
                // labeled prediction -- gated by the same sample floor as
                // every other rate figure in this class, since a frequency
                // computed from a handful of rows is exactly the kind of
                // small-sample noise this codebase's MIN_TRUSTED_SAMPLES
                // convention exists to suppress.
                'frequency_pct' => $this->trusted($directionRows->count())
                    ? round($bucket->count() / $directionRows->count() * 100, 2) : null,
            ];
        }

        $advanced = $this->advancedPnlMetrics($netRows);

        return array_merge([
            'sample_size' => $rows->count(),
            'evaluated_forecasts' => $evaluated->count(),
            'direction_accuracy_pct' => $this->trusted($evaluated->count()) ? round($hits / $evaluated->count() * 100, 2) : null,
            'range_hit_rate_pct' => $this->trusted($ranges->count()) ? round($rangeHits / $ranges->count() * 100, 2) : null,
            'price_mae' => $errors->isNotEmpty() ? round($errors->avg(), 8) : null,
            'median_price_error' => $errors->isNotEmpty() ? round($this->median($errors->values()->all()), 8) : null,
            'net_profit_rate_pct' => $this->trusted($netRows->count())
                ? round($netRows->filter(fn ($row) => (float) data_get($row, 'net_pnl_booked', 0) > 0)->count() / $netRows->count() * 100, 2) : null,
            'net_pnl' => round($netPnl, 8),
            'direction_breakdown' => $directionBreakdown,
            'status' => $this->trusted($evaluated->count()) ? 'observed_sample' : 'insufficient_data',
            'minimum_trusted_samples' => self::MIN_TRUSTED_SAMPLES,
        ], $advanced);
    }

    /**
     * Additive (holdout-validation task, metric #2): profit factor, max
     * drawdown, average win/loss, and a Sharpe-like ratio, all derived from
     * the SAME `net_pnl_booked`-per-row data `summarize()` already
     * aggregates into `net_pnl` -- no new data source, no fabricated
     * figures below the documented sample floors (both fields are null,
     * never a small-sample number dressed up as meaningful).
     *
     * Formulas (all operate on the set of rows with a non-null
     * `net_pnl_booked`, i.e. the same $netRows `summarize()` already
     * computes `net_pnl`/`net_profit_rate_pct` from):
     *
     * - profit_factor = gross_profit / gross_loss, where gross_profit is
     *   the sum of net_pnl_booked over rows with net_pnl_booked > 0 and
     *   gross_loss is the absolute value of the sum of net_pnl_booked over
     *   rows with net_pnl_booked < 0. Undefined (null) if there are no
     *   losing rows (division by zero) or the sample is below
     *   MIN_TRUSTED_SAMPLES.
     * - avg_win = mean(net_pnl_booked) over winning rows (net_pnl_booked >
     *   0) only. avg_loss = mean(net_pnl_booked) over losing rows
     *   (net_pnl_booked < 0) only -- kept negative (the row's own signed
     *   P&L), not reported as an absolute magnitude, since "average loss"
     *   should read like a P&L figure, not a distance. Break-even rows
     *   (net_pnl_booked === 0) are excluded from both.
     * - sharpe_like_ratio = mean(net_pnl_booked) / sample_stdev(net_pnl_booked)
     *   over ALL netRows (wins, losses, and break-evens) -- explicitly a
     *   "Sharpe-like" ratio, NOT a real Sharpe ratio: real Sharpe uses
     *   continuously-compounded periodic RETURNS relative to a risk-free
     *   rate over a fixed time period, whereas this uses discrete, absolute
     *   per-trade P&L amounts with no time-normalization and no risk-free
     *   rate subtracted. It answers "how much average P&L per unit of P&L
     *   volatility has this model produced", not a finance-textbook Sharpe
     *   ratio. Sample standard deviation (Bessel-corrected, N-1 denominator)
     *   is used, matching typical small-sample statistical practice. Null
     *   if stdev is 0 (no variance -- ratio undefined) or below
     *   MIN_TRUSTED_SAMPLES.
     * - max_drawdown_amount / max_drawdown_pct = the largest peak-to-trough
     *   decline in the CHRONOLOGICAL cumulative sum of net_pnl_booked.
     *   Rows are ordered by their own 'settled_at' field when every row in
     *   the sample has one (the real, current shape produced by
     *   QuickTradeResearchService::report(), which is what every caller of
     *   this method today actually passes in); if any row is missing
     *   'settled_at', the rows are treated as already being in the
     *   chronological order the caller supplied (documented assumption --
     *   this method never invents a timestamp). Walking that ordered
     *   sequence, cumulative P&L is tracked alongside its running peak;
     *   drawdown at each point is (peak - cumulative), and the maximum such
     *   value across the whole sequence is max_drawdown_amount.
     *   max_drawdown_pct expresses that amount as a percentage of the peak
     *   it occurred against (null if the peak was never positive -- a
     *   percentage decline from a non-positive baseline is not a
     *   meaningful figure). Gated by MIN_DRAWDOWN_SEQUENCE_POINTS, not
     *   MIN_TRUSTED_SAMPLES (see that constant's own docblock for why).
     *
     * @param  Collection  $netRows  Rows with a non-null net_pnl_booked
     *         (already filtered by the caller, matching summarize()'s own
     *         $netRows).
     */
    private function advancedPnlMetrics(Collection $netRows): array
    {
        $count = $netRows->count();
        $pnlOf = fn ($row) => (float) data_get($row, 'net_pnl_booked', 0);

        $wins = $netRows->filter(fn ($row) => $pnlOf($row) > 0);
        $losses = $netRows->filter(fn ($row) => $pnlOf($row) < 0);
        $grossProfit = (float) $wins->sum($pnlOf);
        $grossLoss = abs((float) $losses->sum($pnlOf));

        $profitFactor = null;
        if ($this->trusted($count) && $grossLoss > 0.0) {
            $profitFactor = round($grossProfit / $grossLoss, 4);
        }

        $avgWin = $this->trusted($count) && $wins->isNotEmpty() ? round($wins->avg($pnlOf), 8) : null;
        $avgLoss = $this->trusted($count) && $losses->isNotEmpty() ? round($losses->avg($pnlOf), 8) : null;

        $sharpeLike = null;
        if ($this->trusted($count)) {
            $values = $netRows->map($pnlOf)->values()->all();
            $mean = array_sum($values) / count($values);
            $stdev = $this->sampleStdev($values, $mean);
            $sharpeLike = $stdev > 0.0 ? round($mean / $stdev, 4) : null;
        }

        [$maxDrawdownAmount, $maxDrawdownPct] = $this->maxDrawdown($netRows, $pnlOf);

        return [
            'profit_factor' => $profitFactor,
            'avg_win' => $avgWin,
            'avg_loss' => $avgLoss,
            'sharpe_like_ratio' => $sharpeLike,
            'max_drawdown_amount' => $maxDrawdownAmount,
            'max_drawdown_pct' => $maxDrawdownPct,
            'minimum_drawdown_sequence_points' => self::MIN_DRAWDOWN_SEQUENCE_POINTS,
        ];
    }

    /**
     * @return array{0: ?float, 1: ?float} [max_drawdown_amount, max_drawdown_pct]
     */
    private function maxDrawdown(Collection $netRows, callable $pnlOf): array
    {
        if ($netRows->count() < self::MIN_DRAWDOWN_SEQUENCE_POINTS) {
            return [null, null];
        }

        $hasSettledAt = $netRows->every(fn ($row) => data_get($row, 'settled_at') !== null);
        $ordered = $hasSettledAt
            ? $netRows->sortBy(fn ($row) => data_get($row, 'settled_at'))->values()
            : $netRows->values();

        $cumulative = 0.0;
        $peak = 0.0;
        $maxDrawdown = 0.0;
        $peakAtMaxDrawdown = 0.0;

        foreach ($ordered as $row) {
            $cumulative += $pnlOf($row);
            if ($cumulative > $peak) {
                $peak = $cumulative;
            }
            $drawdown = $peak - $cumulative;
            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
                $peakAtMaxDrawdown = $peak;
            }
        }

        $maxDrawdownPct = $peakAtMaxDrawdown > 0.0 ? round(($maxDrawdown / $peakAtMaxDrawdown) * 100, 2) : null;

        return [round($maxDrawdown, 8), $maxDrawdownPct];
    }

    /** Sample (Bessel-corrected, N-1) standard deviation. */
    private function sampleStdev(array $values, float $mean): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0.0;
        }
        $sumSquaredDiffs = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values));

        return sqrt($sumSquaredDiffs / ($count - 1));
    }

    private function trusted(int $count): bool { return $count >= self::MIN_TRUSTED_SAMPLES; }

    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        return $count % 2 === 0 ? ((float) $values[$middle - 1] + (float) $values[$middle]) / 2 : (float) $values[$middle];
    }
}
