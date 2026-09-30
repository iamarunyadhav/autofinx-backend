<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\FreshData\PredictionShadowPerformanceGuard;
use Tests\TestCase;

final class PredictionShadowPerformanceGuardTest extends TestCase
{
    public function test_it_waits_for_enough_settled_shadow_observations(): void
    {
        $assessment = (new PredictionShadowPerformanceGuard)->assess(62, 40, 29);

        self::assertSame('INSUFFICIENT_LIVE_DATA', $assessment['status']);
    }

    public function test_it_flags_material_live_degradation_for_review_only(): void
    {
        $assessment = (new PredictionShadowPerformanceGuard)->assess(65, 54, 30);

        self::assertSame('REVIEW_REQUIRED', $assessment['status']);
        self::assertSame('live_shadow_accuracy_materially_below_holdout', $assessment['reason']);
    }

    public function test_it_marks_healthy_shadow_evidence_without_promoting_anything(): void
    {
        $assessment = (new PredictionShadowPerformanceGuard)->assess(60, 58, 30);

        self::assertSame('SHADOW_HEALTHY', $assessment['status']);
    }
}
