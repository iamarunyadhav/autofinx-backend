<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\PredictionDecisionNormalizer;
use PHPUnit\Framework\TestCase;

final class PredictionDecisionNormalizerTest extends TestCase
{
    public function test_it_normalizes_provider_output_and_neutral_to_wait(): void
    {
        $result = (new PredictionDecisionNormalizer)->normalize([
            'provider' => 'lightgbm', 'provider_version' => 'v1', 'direction' => 'neutral',
            'confidence' => 140, 'predicted_expiry_price' => 105,
        ]);

        self::assertSame('WAIT', $result['decision']);
        self::assertSame(100.0, $result['confidence']);
        self::assertSame(105.0, $result['target_price']);
        self::assertTrue($result['shadow_only']);
        self::assertFalse($result['execution']);
    }

    public function test_invalid_provider_direction_fails_safe_to_wait(): void
    {
        $result = (new PredictionDecisionNormalizer)->normalize([
            'direction' => 'MOON', 'available' => false, 'reason' => 'model_not_ready',
        ]);

        self::assertSame('WAIT', $result['decision']);
        self::assertSame('UNAVAILABLE', $result['status']);
        self::assertFalse($result['influence']);
    }
}
