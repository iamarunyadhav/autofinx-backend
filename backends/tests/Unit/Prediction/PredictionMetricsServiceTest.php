<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PredictionMetricsService;
use PHPUnit\Framework\TestCase;

final class PredictionMetricsServiceTest extends TestCase
{
    public function test_it_calculates_direction_error_range_and_calibration_metrics(): void
    {
        $result = (new PredictionMetricsService)->calculate([
            ['predicted_direction' => 'BUY', 'actual_direction' => 'BUY', 'direction_correct' => true, 'confidence' => 80,
                'predicted_expiry_price' => 105, 'expiry_price' => 104, 'range_correct' => true, 'buy_probability' => 0.8],
            ['predicted_direction' => 'BUY', 'actual_direction' => 'SELL', 'direction_correct' => false, 'confidence' => 80,
                'predicted_expiry_price' => 102, 'expiry_price' => 100, 'range_correct' => false, 'buy_probability' => 0.8],
        ]);

        self::assertSame(50.0, $result['direction_accuracy_pct']);
        self::assertSame(50.0, $result['buy_precision_pct']);
        self::assertSame(1.5, $result['mae']);
        self::assertSame(50.0, $result['range_coverage_pct']);
        self::assertArrayHasKey('80', $result['confidence_calibration']);
    }
}
