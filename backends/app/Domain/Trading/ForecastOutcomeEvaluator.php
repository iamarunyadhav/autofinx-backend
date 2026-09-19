<?php

namespace App\Domain\Trading;

use Carbon\CarbonImmutable;

/**
 * Pure, reusable post-horizon evaluator. It never places orders and never
 * changes a forecast. Future/actual values are labels only.
 */
class ForecastOutcomeEvaluator
{
    public function evaluate(array $forecast, array $samples): array
    {
        $unknown = [
            'status' => 'unavailable', 'predicted_direction' => null,
            'actual_direction' => null, 'direction_correct' => null,
            'predicted_price' => null, 'actual_price' => null,
            'price_error' => null, 'price_error_pct' => null,
            'price_in_predicted_range' => null, 'target_at' => null,
        ];

        if (($forecast['available'] ?? false) !== true) return $unknown;
        $base = (float) ($forecast['reference_price'] ?? $forecast['current_price'] ?? 0);
        $predicted = (float) ($forecast['predicted_expiry_price'] ?? $forecast['expected_mid_price'] ?? 0);
        $horizon = (int) ($forecast['horizon_seconds'] ?? 0);
        $generatedAt = $forecast['generated_at'] ?? null;
        if ($base <= 0 || $predicted <= 0 || $horizon <= 0 || ! $generatedAt) return $unknown;

        $target = CarbonImmutable::parse($generatedAt)->addSeconds($horizon);
        $matching = array_values(array_filter($samples, static function (array $sample) use ($target): bool {
            $time = (int) ($sample['time_ms'] ?? 0);
            return $time >= $target->getTimestampMs()
                && $time <= $target->getTimestampMs() + (int) config('quick_trade_research.max_outcome_delay_seconds', 10) * 1000;
        }));
        usort($matching, static fn (array $a, array $b) => ($a['time_ms'] ?? 0) <=> ($b['time_ms'] ?? 0));

        $result = array_merge($unknown, [
            'status' => $matching === [] ? 'missing_horizon_sample' : 'evaluated',
            'predicted_direction' => $this->direction($base, $predicted),
            'predicted_price' => $predicted,
            'target_at' => $target->toIso8601String(),
        ]);
        if ($matching === []) return $result;

        $actual = (float) $matching[0]['price'];
        $actualDirection = $this->direction($base, $actual);
        $low = (float) ($forecast['predicted_low'] ?? $forecast['expected_low'] ?? 0);
        $high = (float) ($forecast['predicted_high'] ?? $forecast['expected_high'] ?? 0);
        $error = $actual - $predicted;
        return array_merge($result, [
            'actual_price' => $actual,
            'actual_direction' => $actualDirection,
            'direction_correct' => $result['predicted_direction'] === $actualDirection,
            'price_error' => round($error, 8),
            'price_error_pct' => round($error / $predicted * 100, 6),
            'price_in_predicted_range' => $low > 0 && $high > 0 ? $actual >= $low && $actual <= $high : null,
            'sample_delay_ms' => (int) $matching[0]['time_ms'] - $target->getTimestampMs(),
        ]);
    }

    private function direction(float $from, float $to): string
    {
        return abs($to - $from) < max(1e-8, abs($from) * 1e-10) ? 'neutral' : ($to > $from ? 'buy' : 'sell');
    }
}
