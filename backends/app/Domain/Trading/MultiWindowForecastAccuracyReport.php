<?php

namespace App\Domain\Trading;

/**
 * VNext Dynamic Decision Engine, Phase A item 3 -- per-window (short/medium/
 * long) accuracy/win-rate/net-PnL breakdown over
 * `multi_window_forecast_audits` rows, reported SEPARATELY per window label
 * rather than blended into one aggregate number (the user's own "3 separate
 * ah pirichu" requirement).
 *
 * Deliberately a smaller sibling of QuickTradeAccuracyReport, not a
 * duplicate of its full D1-D4 apparatus (confidence calibration/expiry-score
 * buckets/manual-override splits do not apply here -- there is no real
 * trade or manual action behind a comparison-only forecast). Reuses the
 * SAME honesty rules and the SAME `quick_trade.bucket_min_samples`
 * threshold so "insufficient" means the same thing platform-wide.
 *
 * `win_rate_pct`/`avg_net_pnl_usdt` are HYPOTHETICAL: no order was ever
 * placed for these rows (see MultiWindowForecastAuditService::evaluateDue(),
 * which reuses QuickTradeResearchService::hypotheticalWaitOutcome() -- the
 * exact same math the untraded-WAIT-shadow feature already uses).
 */
class MultiWindowForecastAccuracyReport
{
    /**
     * @param  iterable<array<string,mixed>>  $rows  MultiWindowForecastAudit rows, as plain arrays
     * @return array<string,mixed>
     */
    public function generate(iterable $rows): array
    {
        $rows = is_array($rows) ? $rows : iterator_to_array($rows);
        $minBucket = max(1, (int) config('quick_trade.bucket_min_samples', 30));

        $byWindow = ['short' => [], 'medium' => [], 'long' => []];
        foreach ($rows as $row) {
            $label = (string) ($row['window_label'] ?? 'unknown');
            $byWindow[$label][] = $row;
        }

        return [
            'schema_version' => 1,
            'generated_at' => now()->toIso8601String(),
            'sample_size' => count($rows),
            'thresholds' => ['bucket_min_samples' => $minBucket],
            'windows' => [
                'short' => $this->windowMetrics($byWindow['short'] ?? [], $minBucket),
                'medium' => $this->windowMetrics($byWindow['medium'] ?? [], $minBucket),
                'long' => $this->windowMetrics($byWindow['long'] ?? [], $minBucket),
            ],
            'note' => 'Each window is reported separately and never averaged/fused. win_rate_pct and avg_net_pnl_usdt are HYPOTHETICAL (no order was placed for these rows) -- the same reference-notional math the untraded-WAIT-shadow feature already uses. Windows under the minimum sample size are `insufficient`, never a percentage.',
        ];
    }

    /**
     * @param  array<int, array<string,mixed>>  $rows
     */
    private function windowMetrics(array $rows, int $minBucket): array
    {
        $n = count($rows);
        $evaluated = array_values(array_filter($rows, static fn (array $r) => ($r['status'] ?? null) === 'evaluated'));
        $pending = count(array_filter($rows, static fn (array $r) => ($r['status'] ?? null) === 'pending'));
        $notApplicable = count(array_filter($rows, static fn (array $r) => ($r['status'] ?? null) === 'not_applicable'));

        $scored = array_values(array_filter($evaluated, static fn (array $r) => $r['direction_correct'] !== null));
        $correct = count(array_filter($scored, static fn (array $r) => $r['direction_correct'] === true));
        $directionAccuracy = count($scored) >= $minBucket ? round($correct / count($scored) * 100, 2) : null;

        $pnlRows = array_values(array_filter($evaluated, static fn (array $r) => $r['hypothetical_net_usdt'] !== null));
        $wins = count(array_filter($pnlRows, static fn (array $r) => (float) $r['hypothetical_net_usdt'] > 0));
        $winRate = count($pnlRows) >= $minBucket ? round($wins / count($pnlRows) * 100, 2) : null;
        $avgNetPnl = $pnlRows === [] ? null
            : round(array_sum(array_map(static fn (array $r) => (float) $r['hypothetical_net_usdt'], $pnlRows)) / count($pnlRows), 8);

        return [
            'n' => $n,
            'status' => $n < $minBucket ? 'insufficient' : 'observed',
            'evaluated' => count($evaluated),
            'pending' => $pending,
            'not_applicable' => $notApplicable,
            'direction_scored' => count($scored),
            'direction_correct' => $correct,
            'direction_accuracy_pct' => $directionAccuracy,
            'win_rate_pct' => $winRate,
            'avg_net_pnl_usdt' => $avgNetPnl,
        ];
    }
}
