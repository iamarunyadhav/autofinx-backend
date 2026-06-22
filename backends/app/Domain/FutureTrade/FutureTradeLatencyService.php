<?php

namespace App\Domain\FutureTrade;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FutureTradeLatencyService
{
    private const MAX_EVENTS = 120;
    private const TTL_SECONDS = 86400;

    public function start(string $workspaceId, string $flow, string $stage, array $context = []): array
    {
        return [
            'trace_id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'flow' => $flow,
            'stage' => $stage,
            'context' => $context,
            'started_at' => now()->toIso8601String(),
            'started_hrtime' => hrtime(true),
        ];
    }

    public function finish(array $trace, string $status = 'ok', array $extra = []): array
    {
        $latencyMs = isset($trace['started_hrtime'])
            ? (int) round((hrtime(true) - (int) $trace['started_hrtime']) / 1_000_000)
            : null;

        $event = [
            'trace_id' => (string) ($trace['trace_id'] ?? Str::uuid()),
            'workspace_id' => (string) ($trace['workspace_id'] ?? ''),
            'flow' => (string) ($trace['flow'] ?? 'future_trade'),
            'stage' => (string) ($trace['stage'] ?? 'unknown'),
            'status' => $status,
            'latency_ms' => $latencyMs,
            'started_at' => $trace['started_at'] ?? null,
            'finished_at' => now()->toIso8601String(),
            'context' => (array) ($trace['context'] ?? []),
            'extra' => $extra,
        ];

        $this->push((string) $event['workspace_id'], $event);

        return $event;
    }

    public function record(string $workspaceId, string $flow, string $stage, ?int $latencyMs, string $status = 'ok', array $context = []): array
    {
        $event = [
            'trace_id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'flow' => $flow,
            'stage' => $stage,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'started_at' => null,
            'finished_at' => now()->toIso8601String(),
            'context' => $context,
            'extra' => [],
        ];

        $this->push($workspaceId, $event);

        return $event;
    }

    public function summary(string $workspaceId): array
    {
        $events = $this->events($workspaceId);
        $byStage = collect($events)
            ->filter(fn (array $event) => is_numeric($event['latency_ms'] ?? null))
            ->groupBy(fn (array $event) => ($event['flow'] ?? 'future_trade').'.'.($event['stage'] ?? 'unknown'))
            ->map(function ($rows): array {
                $values = $rows->pluck('latency_ms')->map(fn ($value) => (int) $value)->values();

                return [
                    'samples' => $values->count(),
                    'avg_ms' => $values->count() ? (int) round($values->avg()) : null,
                    'max_ms' => $values->count() ? (int) $values->max() : null,
                    'last_ms' => $values->count() ? (int) $values->first() : null,
                ];
            })
            ->all();

        $latest = $events[0] ?? null;
        $maxMs = collect($byStage)->pluck('max_ms')->filter()->max();

        return [
            'state' => $maxMs === null ? 'waiting' : ($maxMs >= 2000 ? 'blocked' : ($maxMs >= 500 ? 'warning' : 'healthy')),
            'summary' => $maxMs === null
                ? 'No latency samples recorded yet.'
                : "Worst recorded latency is {$maxMs} ms.",
            'latest' => $latest,
            'by_stage' => $byStage,
            'recent' => array_slice($events, 0, 25),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function events(string $workspaceId): array
    {
        return (array) Cache::get($this->cacheKey($workspaceId), []);
    }

    private function push(string $workspaceId, array $event): void
    {
        if ($workspaceId === '') {
            return;
        }

        $events = $this->events($workspaceId);
        array_unshift($events, $event);
        $events = array_slice($events, 0, self::MAX_EVENTS);
        Cache::put($this->cacheKey($workspaceId), $events, self::TTL_SECONDS);
    }

    private function cacheKey(string $workspaceId): string
    {
        return "autofinx_ft:latency:{$workspaceId}:events";
    }
}
