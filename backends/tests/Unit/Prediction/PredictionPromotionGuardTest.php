<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PredictionPromotionGuard;
use PHPUnit\Framework\TestCase;

final class PredictionPromotionGuardTest extends TestCase
{
    public function test_default_configuration_is_blocked(): void
    {
        $result = (new PredictionPromotionGuard)->assess(['status' => 'MANUAL_REVIEW_APPROVED'], []);

        self::assertSame('BLOCKED', $result['status']);
        self::assertFalse($result['eligible']);
        self::assertFalse($result['changes_applied']);
    }

    public function test_even_eligible_assessment_cannot_execute_automatically(): void
    {
        $result = (new PredictionPromotionGuard)->assess(
            ['status' => 'MANUAL_REVIEW_APPROVED'],
            ['enabled' => true, 'influence' => true, 'execution' => true, 'risk_controls_passed' => true]
        );

        self::assertSame('ELIGIBLE_FOR_RESTRICTED_PROMOTION', $result['status']);
        self::assertTrue($result['eligible']);
        self::assertFalse($result['execution']);
        self::assertFalse($result['changes_applied']);
    }
}
