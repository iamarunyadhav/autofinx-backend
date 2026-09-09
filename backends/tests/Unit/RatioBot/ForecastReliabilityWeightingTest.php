<?php

namespace Tests\Unit\RatioBot;

use App\Domain\RatioBot\ForecastReliabilityWeighting;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Table-tests the exact weight bands extracted out of
 * RunRatioBotEvaluationJob::resolveDirectionWithReliabilityWeighting() into
 * App\Domain\RatioBot\ForecastReliabilityWeighting -- a mechanical extraction,
 * these thresholds must not drift.
 */
class ForecastReliabilityWeightingTest extends TestCase
{
    #[DataProvider('hitRateProvider')]
    public function test_weight_for_hit_rate(?float $hitRatePct, float $expectedWeight): void
    {
        $this->assertSame($expectedWeight, ForecastReliabilityWeighting::weightForHitRate($hitRatePct));
    }

    public static function hitRateProvider(): array
    {
        return [
            'null hit rate' => [null, 0.0],
            'zero hit rate' => [0.0, 0.0],
            'exactly 50' => [50.0, 0.0],
            'just above 50' => [50.01, 0.10],
            'exactly 60' => [60.0, 0.10],
            'just above 60' => [60.01, 0.20],
            'exactly 70' => [70.0, 0.20],
            'just above 70' => [70.01, 0.30],
            'perfect hit rate' => [100.0, 0.30],
        ];
    }
}
