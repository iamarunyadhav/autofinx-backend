<?php

namespace App\Domain\Prediction;

/** Converts provider-shaped output into one stable, non-executing research schema. */
final class PredictionDecisionNormalizer
{
    /** @param array<string, mixed> $output */
    public function normalize(array $output): array
    {
        $rawDirection = strtoupper(trim((string) ($output['direction'] ?? $output['predicted_direction'] ?? '')));
        $direction = in_array($rawDirection, ['BUY', 'SELL', 'WAIT', 'NEUTRAL'], true)
            ? ($rawDirection === 'NEUTRAL' ? 'WAIT' : $rawDirection)
            : 'WAIT';
        $confidence = $this->number($output['confidence'] ?? null);
        $confidence = $confidence === null ? null : (float) max(0, min(100, $confidence));

        return [
            'decision' => $direction,
            'confidence' => $confidence,
            'current_price' => $this->number($output['current_price'] ?? null),
            'target_price' => $this->number($output['predicted_expiry_price'] ?? $output['target_price'] ?? null),
            'expected_return_pct' => $this->number($output['predicted_return_pct'] ?? $output['expected_return_pct'] ?? null),
            'expected_low' => $this->number($output['predicted_low'] ?? $output['expected_low'] ?? null),
            'expected_high' => $this->number($output['predicted_high'] ?? $output['expected_high'] ?? null),
            'uncertainty' => $this->number($output['uncertainty'] ?? null),
            'risk' => $output['risk'] ?? null,
            'invalidation' => $output['invalidation'] ?? null,
            'provider' => $output['provider'] ?? null,
            'model_version' => $output['provider_version'] ?? $output['model_version'] ?? null,
            'status' => ($output['available'] ?? true) === true ? 'AVAILABLE' : 'UNAVAILABLE',
            'reason' => $output['reason'] ?? null,
            'shadow_only' => true,
            'influence' => false,
            'execution' => false,
        ];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
