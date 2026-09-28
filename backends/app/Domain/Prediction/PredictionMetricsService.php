<?php

namespace App\Domain\Prediction;

/** Pure metrics calculator; it never promotes or enables a provider. */
final class PredictionMetricsService
{
    /** @param array<int, array<string, mixed>> $rows */
    public function calculate(array $rows): array
    {
        $evaluated = array_values(array_filter($rows, static fn (array $row): bool => is_bool($row['direction_correct'] ?? null)));
        $metrics = [
            'samples' => count($evaluated),
            'direction_accuracy_pct' => null,
            'buy_precision_pct' => null, 'buy_recall_pct' => null,
            'sell_precision_pct' => null, 'sell_recall_pct' => null,
            'mae' => null, 'rmse' => null, 'range_coverage_pct' => null,
            'brier_score' => null, 'confidence_calibration' => [],
        ];
        if ($evaluated === []) return $metrics;

        $metrics['direction_accuracy_pct'] = round(count(array_filter($evaluated, static fn (array $row): bool => $row['direction_correct'])) / count($evaluated) * 100, 2);
        foreach (['BUY', 'SELL'] as $direction) {
            $predicted = array_values(array_filter($evaluated, static fn (array $row): bool => strtoupper((string) ($row['predicted_direction'] ?? '')) === $direction));
            $actual = array_values(array_filter($evaluated, static fn (array $row): bool => strtoupper((string) ($row['actual_direction'] ?? '')) === $direction));
            $hits = count(array_filter($predicted, static fn (array $row): bool => $row['direction_correct']));
            $metrics[strtolower($direction).'_precision_pct'] = $predicted ? round($hits / count($predicted) * 100, 2) : null;
            $metrics[strtolower($direction).'_recall_pct'] = $actual ? round($hits / count($actual) * 100, 2) : null;
        }

        $errors = [];
        $rangeHits = 0; $rangeCount = 0; $brier = [];
        foreach ($evaluated as $row) {
            if (is_numeric($row['predicted_expiry_price'] ?? null) && is_numeric($row['expiry_price'] ?? null)) {
                $errors[] = (float) $row['predicted_expiry_price'] - (float) $row['expiry_price'];
            }
            if (is_bool($row['range_correct'] ?? null)) { $rangeCount++; $rangeHits += $row['range_correct'] ? 1 : 0; }
            if (is_numeric($row['buy_probability'] ?? null) && isset($row['actual_direction'])) {
                $actualBuy = strtoupper((string) $row['actual_direction']) === 'BUY' ? 1 : 0;
                $probability = max(0, min(1, (float) $row['buy_probability'] > 1 ? (float) $row['buy_probability'] / 100 : (float) $row['buy_probability']));
                $brier[] = ($probability - $actualBuy) ** 2;
            }
        }
        if ($errors) {
            $metrics['mae'] = round(array_sum(array_map('abs', $errors)) / count($errors), 8);
            $metrics['rmse'] = round(sqrt(array_sum(array_map(static fn (float $error): float => $error ** 2, $errors)) / count($errors)), 8);
        }
        if ($rangeCount) $metrics['range_coverage_pct'] = round($rangeHits / $rangeCount * 100, 2);
        if ($brier) $metrics['brier_score'] = round(array_sum($brier) / count($brier), 8);
        foreach ($evaluated as $row) {
            if (!is_numeric($row['confidence'] ?? null)) continue;
            $bucket = min(90, max(0, (int) floor((float) $row['confidence'] / 10) * 10));
            $metrics['confidence_calibration'][(string) $bucket] ??= ['samples' => 0, 'hits' => 0];
            $metrics['confidence_calibration'][(string) $bucket]['samples']++;
            $metrics['confidence_calibration'][(string) $bucket]['hits'] += $row['direction_correct'] ? 1 : 0;
        }
        foreach ($metrics['confidence_calibration'] as &$bucket) {
            $bucket['accuracy_pct'] = round($bucket['hits'] / $bucket['samples'] * 100, 2);
        }
        unset($bucket);
        return $metrics;
    }
}
