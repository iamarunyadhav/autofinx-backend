<?php

namespace Tests\Unit\Prediction;

use App\Domain\Prediction\ShadowValidationPolicy;
use PHPUnit\Framework\TestCase;

final class ShadowValidationPolicyTest extends TestCase
{
    public function test_it_never_auto_promotes_and_requires_evidence(): void
    {
        $candidate = (new ShadowValidationPolicy)->assess(['samples' => 100, 'direction_accuracy_pct' => 60]);
        $small = (new ShadowValidationPolicy)->assess(['samples' => 10, 'direction_accuracy_pct' => 100]);

        self::assertSame('VALIDATION_CANDIDATE', $candidate['status']);
        self::assertFalse($candidate['auto_promote']);
        self::assertSame('INSUFFICIENT_DATA', $small['status']);
    }
}
