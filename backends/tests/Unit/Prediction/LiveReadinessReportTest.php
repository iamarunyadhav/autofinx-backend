<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\LiveReadinessReport;
use PHPUnit\Framework\TestCase;

final class LiveReadinessReportTest extends TestCase
{
    public function test_insufficient_evidence_is_not_ready(): void
    {
        $result = (new LiveReadinessReport)->generate(['samples' => 499, 'direction_accuracy_pct' => 80]);

        self::assertSame('NOT_READY', $result['status']);
        self::assertFalse($result['auto_promote']);
        self::assertFalse($result['execution_enabled']);
        self::assertContains('sample_size', $result['failed_checks']);
    }

    public function test_even_complete_evidence_requires_human_review(): void
    {
        $result = (new LiveReadinessReport)->generate([
            'samples' => 500, 'non_overlapping_samples' => 100, 'direction_accuracy_pct' => 60,
            'walk_forward_status' => 'PASS', 'calibration_status' => 'PASS', 'stability_status' => 'PASS',
            'p95_latency_ms' => 500, 'drawdown_review_status' => 'PASS',
        ]);

        self::assertSame('MANUAL_REVIEW_REQUIRED', $result['status']);
        self::assertSame('human_review', $result['required_next_step']);
    }
}
