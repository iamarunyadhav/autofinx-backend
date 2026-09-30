<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PredictionAccuracyReport;
use PHPUnit\Framework\TestCase;

final class PredictionAccuracyReportTest extends TestCase
{
    public function test_report_groups_by_provider_and_timeframe_without_fabricating_readiness(): void
    {
        $report = (new PredictionAccuracyReport())->generate([
            ['provider' => 'market_structure', 'timeframe' => '15m', 'direction_correct' => true, 'return_pct' => 1],
            ['provider' => 'market_structure', 'timeframe' => '15m', 'direction_correct' => false, 'return_pct' => -1],
        ]);

        self::assertSame('OK', $report['status']);
        self::assertSame(50.0, $report['groups'][0]['direction_accuracy_pct']);
        self::assertSame('INSUFFICIENT_DATA', $report['groups'][0]['status']);
    }

    public function test_empty_report_is_honest(): void
    {
        self::assertSame('INSUFFICIENT_DATA', (new PredictionAccuracyReport())->generate([])['status']);
    }

    public function test_report_keeps_different_model_versions_separate(): void
    {
        $report = (new PredictionAccuracyReport())->generate([
            ['provider' => 'external_ml', 'provider_version' => 'btc-v1', 'timeframe' => '5m', 'direction_correct' => true],
            ['provider' => 'external_ml', 'provider_version' => 'btc-v2', 'timeframe' => '5m', 'direction_correct' => false],
        ]);

        self::assertCount(2, $report['groups']);
        self::assertSame('btc-v1', $report['groups'][0]['provider_version']);
        self::assertSame(100.0, $report['groups'][0]['direction_accuracy_pct']);
        self::assertSame('btc-v2', $report['groups'][1]['provider_version']);
        self::assertSame(0.0, $report['groups'][1]['direction_accuracy_pct']);
    }
}
