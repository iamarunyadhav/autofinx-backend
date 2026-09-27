<?php

namespace App\Domain\Prediction;

final class PredictionAccuracyReport
{
    /** @param array<int, array<string, mixed>> $rows */
    public function generate(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $provider = (string) ($row['provider'] ?? 'unknown');
            $timeframe = (string) ($row['timeframe'] ?? 'unknown');
            $key = $provider.'|'.$timeframe;
            $groups[$key] ??= ['provider' => $provider, 'timeframe' => $timeframe, 'samples' => 0, 'hits' => 0, 'returns' => []];
            $groups[$key]['samples']++;
            if (($row['direction_correct'] ?? false) === true) $groups[$key]['hits']++;
            if (isset($row['return_pct']) && is_numeric($row['return_pct'])) $groups[$key]['returns'][] = (float) $row['return_pct'];
        }

        $result = array_map(static function (array $group): array {
            $samples = $group['samples'];
            $returns = $group['returns'];
            return [
                'provider' => $group['provider'], 'timeframe' => $group['timeframe'],
                'samples' => $samples, 'hits' => $group['hits'],
                'direction_accuracy_pct' => $samples ? round($group['hits'] / $samples * 100, 2) : null,
                'average_return_pct' => $returns ? round(array_sum($returns) / count($returns), 8) : null,
                'status' => $samples >= 30 ? 'READY' : 'INSUFFICIENT_DATA',
            ];
        }, array_values($groups));

        return ['schema_version' => 1, 'status' => $result ? 'OK' : 'INSUFFICIENT_DATA', 'groups' => $result];
    }
}
