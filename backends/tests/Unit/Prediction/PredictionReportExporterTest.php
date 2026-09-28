<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PredictionReportExporter;
use PHPUnit\Framework\TestCase;

final class PredictionReportExporterTest extends TestCase
{
    public function test_markdown_export_is_human_readable_and_honest(): void
    {
        $markdown = (new PredictionReportExporter)->markdown([
            'status' => 'OK', 'summary' => [['provider' => 'lightgbm', 'samples' => 2, 'evaluated' => 2, 'direction_accuracy_pct' => 50, 'status' => 'INSUFFICIENT_DATA']],
            'timeline' => [],
        ]);
        self::assertStringContainsString('# AutoFinX Prediction Report', $markdown);
        self::assertStringContainsString('INSUFFICIENT_DATA', $markdown);
    }
}
