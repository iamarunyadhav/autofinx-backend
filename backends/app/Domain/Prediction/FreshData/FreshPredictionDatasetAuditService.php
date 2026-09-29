<?php

namespace App\Domain\Prediction\FreshData;

use App\Models\PredictionFreshDataset;
use App\Models\PredictionFreshDatasetCandle;
use Illuminate\Support\Carbon;

final class FreshPredictionDatasetAuditService
{
    /** @return array<string, mixed> */
    public function report(PredictionFreshDataset $dataset): array
    {
        $pairs = [];
        foreach ((array) $dataset->symbols as $symbol) {
            foreach ((array) $dataset->timeframes as $timeframe) {
                $pairs[] = $this->pairReport($dataset, strtoupper((string) $symbol), strtolower((string) $timeframe));
            }
        }

        return [
            'dataset' => ['id' => $dataset->id, 'name' => $dataset->name, 'status' => $dataset->status, 'started_at' => $dataset->started_at?->toIso8601String()],
            'pairs' => $pairs,
            'ready_pairs' => count(array_filter($pairs, static fn (array $pair): bool => $pair['state'] === 'READY_FOR_TRAINING')),
            'total_pairs' => count($pairs),
            'execution' => false,
            'influence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function pairReport(PredictionFreshDataset $dataset, string $symbol, string $timeframe): array
    {
        $rows = PredictionFreshDatasetCandle::query()
            ->where('dataset_id', $dataset->id)->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->orderBy('open_time')->get(['open_time', 'close_time', 'quality']);
        $expectedSeconds = $this->intervalSeconds($timeframe);
        $gaps = 0;
        $misaligned = 0;
        $previous = null;
        foreach ($rows as $row) {
            // Fresh canonical timestamps are persisted as UTC DATETIME values.
            // Eloquent's normal datetime cast uses the application timezone, which
            // would turn an exact UTC 1h boundary into :30 on an Asia/Colombo app.
            $open = Carbon::parse($row->getRawOriginal('open_time'), 'UTC');
            if ($open->getTimestamp() % $expectedSeconds !== 0 || ! (($row->quality['closed'] ?? false) && ($row->quality['aligned'] ?? false))) $misaligned++;
            if ($previous && $previous->diffInSeconds($open) > (int) ($expectedSeconds * 1.5)) $gaps++;
            $previous = $open;
        }
        $latest = $rows->last()?->getRawOriginal('close_time');
        $latestAt = $latest ? Carbon::parse($latest, 'UTC') : null;
        $staleSeconds = $latestAt?->diffInSeconds(now('UTC'));
        $minimum = $this->minimumRows($timeframe);
        $state = $rows->count() < $minimum ? 'COLLECTING' : ($gaps > 0 || $misaligned > 0 ? 'QUALITY_BLOCKED' : 'READY_FOR_TRAINING');
        return [
            'symbol' => $symbol, 'timeframe' => $timeframe, 'rows' => $rows->count(), 'minimum_rows' => $minimum,
            'gaps' => $gaps, 'misaligned' => $misaligned, 'latest_close_at' => $latestAt?->toIso8601String(),
            'stale_seconds' => $staleSeconds, 'state' => $state,
        ];
    }

    private function minimumRows(string $timeframe): int
    {
        return match ($timeframe) { '1m' => 10000, '5m' => 8000, '15m' => 5000, '1h' => 2500, '4h' => 1000, default => PHP_INT_MAX };
    }

    private function intervalSeconds(string $timeframe): int
    {
        return match ($timeframe) { '1m' => 60, '5m' => 300, '15m' => 900, '1h' => 3600, '4h' => 14400, default => 1 };
    }
}
