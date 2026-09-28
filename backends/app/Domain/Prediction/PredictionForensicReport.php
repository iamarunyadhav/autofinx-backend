<?php

namespace App\Domain\Prediction;

/** Read-only, export-friendly comparison of immutable prediction outcomes. */
final class PredictionForensicReport
{
    /** @param array<int, array<string, mixed>> $rows */
    public function generate(array $rows): array
    {
        $providers = [];
        $timeline = [];

        foreach ($rows as $row) {
            $provider = (string) ($row['provider'] ?? 'unknown');
            $providers[$provider] ??= ['provider' => $provider, 'samples' => 0, 'evaluated' => 0, 'hits' => 0, 'returns' => []];
            $providers[$provider]['samples']++;
            if (is_bool($row['direction_correct'] ?? null)) {
                $providers[$provider]['evaluated']++;
                $providers[$provider]['hits'] += $row['direction_correct'] ? 1 : 0;
            }
            if (is_numeric($row['return_pct'] ?? null)) {
                $providers[$provider]['returns'][] = (float) $row['return_pct'];
            }
            $timeline[] = [
                'id' => $row['id'] ?? null,
                'provider' => $provider,
                'model_version' => $row['provider_version'] ?? null,
                'symbol' => $row['symbol'] ?? null,
                'timeframe' => $row['timeframe'] ?? null,
                'horizon_seconds' => $row['horizon_seconds'] ?? null,
                'predicted_at' => $row['predicted_at'] ?? null,
                'evaluated_at' => $row['evaluated_at'] ?? null,
                'predicted_direction' => $row['predicted_direction'] ?? null,
                'entry_price' => $row['entry_price'] ?? null,
                'expiry_price' => $row['expiry_price'] ?? null,
                'return_pct' => $row['return_pct'] ?? null,
                'direction_correct' => $row['direction_correct'] ?? null,
                'outcome' => data_get($row, 'metadata.outcome'),
            ];
        }

        $summary = array_map(static function (array $group): array {
            $returns = $group['returns'];
            return [
                'provider' => $group['provider'],
                'samples' => $group['samples'],
                'evaluated' => $group['evaluated'],
                'direction_accuracy_pct' => $group['evaluated'] > 0
                    ? round($group['hits'] / $group['evaluated'] * 100, 2) : null,
                'average_return_pct' => $returns ? round(array_sum($returns) / count($returns), 8) : null,
                'status' => $group['evaluated'] >= 30 ? 'READY' : 'INSUFFICIENT_DATA',
            ];
        }, array_values($providers));

        return [
            'schema_version' => 1,
            'status' => $timeline ? 'OK' : 'INSUFFICIENT_DATA',
            'summary' => $summary,
            'timeline' => $timeline,
        ];
    }
}
