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
            $groups[$key] ??= ['provider' => $provider, 'timeframe' => $timeframe, 'rows' => []];
            $groups[$key]['rows'][] = $row;
        }

        $result = array_map(static function (array $group): array {
            $rows = array_values(array_filter($group['rows'], static fn (array $row): bool => is_bool($row['direction_correct'] ?? null)));
            usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['predicted_at'] ?? ''), (string) ($a['predicted_at'] ?? '')));
            $metrics = static function (array $items): array {
                $samples = count($items);
                $hits = count(array_filter($items, static fn (array $row): bool => $row['direction_correct'] === true));
                $returns = array_values(array_filter(array_map(static fn (array $row): mixed => $row['return_pct'] ?? null, $items), 'is_numeric'));
                $netReturns = array_values(array_filter(array_map(static fn (array $row): mixed => $row['buy_net_return_pct'] ?? null, $items), 'is_numeric'));
                return [
                    'samples' => $samples,
                    'hits' => $hits,
                    'direction_accuracy_pct' => $samples ? round($hits / $samples * 100, 2) : null,
                    'average_return_pct' => $returns ? round(array_sum($returns) / count($returns), 8) : null,
                    'average_buy_net_return_pct' => $netReturns ? round(array_sum($netReturns) / count($netReturns), 8) : null,
                    'status' => $samples >= 30 ? 'READY' : 'INSUFFICIENT_DATA',
                ];
            };
            $rolling = [];
            foreach ([20, 50, 100, 500] as $window) {
                $rolling[(string) $window] = $metrics(array_slice($rows, 0, $window));
            }
            $nonOverlapping = [];
            $lastTimestamp = null;
            foreach (array_reverse($rows) as $row) {
                $timestamp = isset($row['predicted_at']) ? strtotime((string) $row['predicted_at']) : false;
                $horizon = max(1, (int) ($row['horizon_seconds'] ?? 0));
                if ($timestamp === false || $lastTimestamp === null || $timestamp >= $lastTimestamp + $horizon) {
                    $nonOverlapping[] = $row;
                    if ($timestamp !== false) $lastTimestamp = $timestamp;
                }
            }
            $current = $metrics($rows);
            return [
                'provider' => $group['provider'], 'timeframe' => $group['timeframe'],
                ...$current,
                'rolling' => $rolling,
                'non_overlapping' => $metrics($nonOverlapping),
            ];
        }, array_values($groups));

        return ['schema_version' => 1, 'status' => $result ? 'OK' : 'INSUFFICIENT_DATA', 'groups' => $result];
    }
}
