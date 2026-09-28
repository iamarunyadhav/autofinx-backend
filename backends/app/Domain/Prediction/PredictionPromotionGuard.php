<?php

namespace App\Domain\Prediction;

/** Final explicit gate. It only reports eligibility; it never flips configuration. */
final class PredictionPromotionGuard
{
    /** @param array<string, mixed> $readiness @param array<string, mixed> $settings */
    public function assess(array $readiness, array $settings): array
    {
        $explicit = ($settings['enabled'] ?? false) === true
            && ($settings['influence'] ?? false) === true
            && ($settings['execution'] ?? false) === true;
        $riskControls = ($settings['risk_controls_passed'] ?? false) === true;
        $manual = ($readiness['status'] ?? '') === 'MANUAL_REVIEW_APPROVED';
        $eligible = $explicit && $riskControls && $manual;

        return [
            'status' => $eligible ? 'ELIGIBLE_FOR_RESTRICTED_PROMOTION' : 'BLOCKED',
            'eligible' => $eligible,
            'reasons' => array_values(array_filter([
                !$explicit ? 'explicit_provider_enablement_required' : null,
                !$riskControls ? 'risk_controls_not_confirmed' : null,
                !$manual ? 'manual_readiness_approval_required' : null,
            ])),
            'execution' => false,
            'changes_applied' => false,
        ];
    }
}
