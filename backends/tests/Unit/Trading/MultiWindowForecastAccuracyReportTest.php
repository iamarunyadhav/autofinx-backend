<?php

namespace Tests\Unit\Trading;

use App\Domain\Trading\MultiWindowForecastAccuracyReport;
use Tests\TestCase;

/**
 * VNext Dynamic Decision Engine, Phase A item 3 -- per-window (short/medium/
 * long) accuracy/win-rate/net-PnL, reported SEPARATELY per window and never
 * blended into one aggregate number.
 */
class MultiWindowForecastAccuracyReportTest extends TestCase
{
    private function row(string $window, string $status, ?bool $correct = null, ?float $netUsdt = null): array
    {
        return [
            'window_label' => $window, 'status' => $status,
            'direction_correct' => $correct, 'hypothetical_net_usdt' => $netUsdt,
        ];
    }

    public function test_each_window_is_reported_separately_never_averaged(): void
    {
        config(['quick_trade.bucket_min_samples' => 2]);
        $report = app(MultiWindowForecastAccuracyReport::class);

        $rows = [
            $this->row('short', 'evaluated', true, 1.0),
            $this->row('short', 'evaluated', false, -0.5),
            $this->row('medium', 'evaluated', true, 2.0),
            $this->row('medium', 'evaluated', true, 3.0),
            $this->row('long', 'pending', null, null),
        ];

        $result = $report->generate($rows);

        // short: 1/2 correct = 50%, avg net = (1.0 + -0.5)/2 = 0.25, 1/2 wins = 50%.
        $this->assertSame(50.0, $result['windows']['short']['direction_accuracy_pct']);
        $this->assertEqualsWithDelta(0.25, $result['windows']['short']['avg_net_pnl_usdt'], 1e-9);
        $this->assertSame(50.0, $result['windows']['short']['win_rate_pct']);

        // medium: 2/2 correct = 100%, both wins.
        $this->assertSame(100.0, $result['windows']['medium']['direction_accuracy_pct']);
        $this->assertSame(100.0, $result['windows']['medium']['win_rate_pct']);

        // long: only 1 row, still pending -- no evaluated data, and below
        // the (test-lowered) min-sample threshold of 2 -> insufficient.
        $this->assertSame('insufficient', $result['windows']['long']['status']);
        $this->assertNull($result['windows']['long']['direction_accuracy_pct']);
        $this->assertNull($result['windows']['long']['win_rate_pct']);

        $this->assertSame(5, $result['sample_size']);
    }

    public function test_a_window_below_the_minimum_sample_size_never_reports_a_fabricated_percentage(): void
    {
        config(['quick_trade.bucket_min_samples' => 30]);
        $report = app(MultiWindowForecastAccuracyReport::class);

        $rows = [$this->row('short', 'evaluated', true, 1.0)];
        $result = $report->generate($rows);

        $this->assertSame('insufficient', $result['windows']['short']['status']);
        $this->assertNull($result['windows']['short']['direction_accuracy_pct']);
        $this->assertNull($result['windows']['short']['win_rate_pct']);
        // n itself is still honestly reported even while insufficient.
        $this->assertSame(1, $result['windows']['short']['n']);
    }

    public function test_an_unscored_row_is_excluded_from_direction_accuracy_not_counted_as_a_miss(): void
    {
        config(['quick_trade.bucket_min_samples' => 1]);
        $report = app(MultiWindowForecastAccuracyReport::class);

        // direction_correct === null (e.g. a NEUTRAL/WAIT-only capture) must
        // never be counted as incorrect.
        $rows = [
            $this->row('short', 'evaluated', true, 1.0),
            $this->row('short', 'evaluated', null, null),
        ];
        $result = $report->generate($rows);

        $this->assertSame(1, $result['windows']['short']['direction_scored']);
        $this->assertSame(100.0, $result['windows']['short']['direction_accuracy_pct']);
    }

    public function test_an_empty_window_reports_zero_not_a_null_crash(): void
    {
        $report = app(MultiWindowForecastAccuracyReport::class);
        $result = $report->generate([]);

        foreach (['short', 'medium', 'long'] as $label) {
            $this->assertSame(0, $result['windows'][$label]['n']);
            $this->assertSame('insufficient', $result['windows'][$label]['status']);
        }
    }
}
