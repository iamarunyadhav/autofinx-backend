<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PredictionForensicReport;
use PHPUnit\Framework\TestCase;

final class PredictionForensicReportTest extends TestCase
{
    public function test_it_returns_provider_summary_and_prediction_timeline(): void
    {
        $report = (new PredictionForensicReport)->generate([
            ['id' => 1, 'provider' => 'lightgbm', 'provider_version' => 'v1', 'symbol' => 'BTCUSDT',
                'predicted_direction' => 'BUY', 'direction_correct' => true, 'return_pct' => 0.4,
                'predicted_at' => '2026-09-28T00:00:00Z', 'metadata' => ['outcome' => ['range_correct' => true]]],
        ]);

        self::assertSame('OK', $report['status']);
        self::assertSame(1, $report['summary'][0]['samples']);
        self::assertSame(100.0, $report['summary'][0]['direction_accuracy_pct']);
        self::assertTrue($report['timeline'][0]['outcome']['range_correct']);
    }
}
