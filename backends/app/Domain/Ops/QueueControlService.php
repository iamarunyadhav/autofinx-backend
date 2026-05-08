<?php

namespace App\Domain\Ops;

use App\Models\SchedulerRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

class QueueControlService
{
    public function dashboard(int $limit = 25): array
    {
        $queues = $this->queueRows();
        $connection = (string) config('queue.default', 'database');
        $backlog = array_sum(array_column($queues, 'pending'));
        $failed = array_sum(array_column($queues, 'failed'));
        $warningThreshold = (int) config('ops.live_readiness.queue_backlog_warning', 100);
        $blockThreshold = (int) config('ops.live_readiness.queue_backlog_block', 500);
        $state = $backlog >= $blockThreshold ? 'blocked' : ($backlog >= $warningThreshold || $failed > 0 ? 'degraded' : 'healthy');

        return [
            'state' => $state,
            'connection' => $connection,
            'driver' => (string) config("queue.connections.{$connection}.driver", $connection),
            'backlog' => $backlog,
            'failed' => $failed,
            'warning_threshold' => $warningThreshold,
            'block_threshold' => $blockThreshold,
            'horizon' => [
                'installed' => class_exists(\Laravel\Horizon\Horizon::class),
                'enabled' => (bool) config('queue_control.horizon_enabled', false),
                'dashboard_url' => '/horizon',
            ],
            'redis_ready' => [
                'configured' => config('queue.default') === 'redis',
                'queue_connection' => config('queue.default'),
                'redis_client' => config('database.redis.client'),
            ],
            'worker' => [
                'restart_signal_available' => true,
                'recommended_command' => (string) config('queue_control.worker_command'),
                'supervisor_command' => 'sudo supervisorctl restart autofinx-queue:*',
                'horizon_command' => 'php artisan horizon',
            ],
            'queues' => $queues,
            'pending_jobs' => $this->pendingJobs($limit),
            'failed_jobs' => $this->failedJobs($limit),
            'scheduler_runs' => SchedulerRun::query()
                ->orderByDesc('started_at')
                ->limit($limit)
                ->get(['id', 'command', 'status', 'started_at', 'finished_at', 'duration_ms'])
                ->values(),
            'recommendations' => $this->recommendations($state, $connection, $backlog, $failed),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function retryFailed(string $id, ?string $queue = null): array
    {
        $ids = $id === 'all' ? $this->failedUuids($queue) : [$this->failedUuidFor($id) ?? $id];

        foreach ($ids as $failedId) {
            Artisan::call('queue:retry', ['id' => [$failedId]]);
        }

        return [
            'action' => 'retry_failed',
            'requested' => count($ids),
            'message' => count($ids) > 0 ? 'Failed jobs were queued for retry.' : 'No failed jobs matched the request.',
            'artisan_output' => trim(Artisan::output()),
        ];
    }

    public function forgetFailed(string $id, ?string $queue = null): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [
                'action' => 'forget_failed',
                'forgotten' => 0,
                'message' => 'Failed jobs table is not available.',
                'artisan_output' => '',
            ];
        }

        $query = DB::table('failed_jobs');
        if ($id === 'all') {
            $query->when($queue, fn ($builder) => $builder->where('queue', $queue));
        } else {
            $query->where(fn ($builder) => $builder->where('id', $id)->orWhere('uuid', $id));
        }

        $forgotten = $query->delete();

        return [
            'action' => 'forget_failed',
            'forgotten' => $forgotten,
            'message' => $forgotten > 0 ? 'Failed jobs were forgotten.' : 'No failed jobs matched the request.',
            'artisan_output' => '',
        ];
    }

    public function clearPending(string $queue, ?string $connection = null): array
    {
        abort_unless((bool) config('queue_control.allow_pending_clear', true), 403, 'Queue clearing is disabled on this server.');

        $connection = $connection ?: (string) config('queue.default', 'database');
        Artisan::call('queue:clear', [
            'connection' => $connection,
            '--queue' => $queue,
            '--force' => true,
        ]);

        return [
            'action' => 'clear_pending',
            'queue' => $queue,
            'connection' => $connection,
            'message' => "Pending jobs were cleared for {$queue}.",
            'artisan_output' => trim(Artisan::output()),
        ];
    }

    public function restartWorkers(): array
    {
        Artisan::call('queue:restart');

        return [
            'action' => 'restart_workers',
            'message' => 'Queue restart signal sent. Supervisor or Horizon workers will restart after the current job.',
            'artisan_output' => trim(Artisan::output()),
        ];
    }

    private function queueRows(): array
    {
        $configured = collect(config('queue_control.queues', []))
            ->map(fn (array $meta, string $name) => [
                'name' => $name,
                'priority' => (int) ($meta['priority'] ?? 0),
                'purpose' => (string) ($meta['purpose'] ?? 'Application jobs'),
                'pending' => 0,
                'reserved' => 0,
                'delayed' => 0,
                'failed' => 0,
                'runtime_size' => null,
                'state' => 'healthy',
            ]);

        if (Schema::hasTable('jobs')) {
            DB::table('jobs')
                ->select('queue')
                ->selectRaw('COUNT(*) as pending')
                ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) as reserved')
                ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END) as delayed', [time()])
                ->groupBy('queue')
                ->get()
                ->each(function ($row) use ($configured) {
                    $name = (string) $row->queue;
                    $current = $configured->get($name, [
                        'name' => $name,
                        'priority' => 0,
                        'purpose' => 'Discovered queue',
                        'pending' => 0,
                        'reserved' => 0,
                        'delayed' => 0,
                        'failed' => 0,
                        'runtime_size' => null,
                        'state' => 'healthy',
                    ]);
                    $current['pending'] = (int) $row->pending;
                    $current['reserved'] = (int) $row->reserved;
                    $current['delayed'] = (int) $row->delayed;
                    $configured->put($name, $current);
                });
        }

        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')
                ->select('queue')
                ->selectRaw('COUNT(*) as failed')
                ->groupBy('queue')
                ->get()
                ->each(function ($row) use ($configured) {
                    $name = (string) $row->queue;
                    $current = $configured->get($name, [
                        'name' => $name,
                        'priority' => 0,
                        'purpose' => 'Discovered queue',
                        'pending' => 0,
                        'reserved' => 0,
                        'delayed' => 0,
                        'failed' => 0,
                        'runtime_size' => null,
                        'state' => 'healthy',
                    ]);
                    $current['failed'] = (int) $row->failed;
                    $configured->put($name, $current);
                });
        }

        return $configured
            ->map(function (array $row) {
                $row['runtime_size'] = $this->runtimeQueueSize($row['name']);
                $row['state'] = $row['failed'] > 0 ? 'failed' : ($row['pending'] >= (int) config('ops.live_readiness.queue_backlog_warning', 100) ? 'backlog' : 'healthy');

                return $row;
            })
            ->sortByDesc('priority')
            ->values()
            ->all();
    }

    private function pendingJobs(int $limit): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        return DB::table('jobs')
            ->orderBy('available_at')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'queue' => (string) $row->queue,
                'attempts' => (int) $row->attempts,
                'reserved' => $row->reserved_at !== null,
                'available_at' => $this->fromTimestamp((int) $row->available_at),
                'created_at' => $this->fromTimestamp((int) $row->created_at),
                ...$this->payloadSummary((string) $row->payload),
            ])
            ->values()
            ->all();
    }

    private function failedJobs(int $limit): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'uuid' => (string) $row->uuid,
                'connection' => (string) $row->connection,
                'queue' => (string) $row->queue,
                'failed_at' => (string) $row->failed_at,
                'exception' => $this->firstLine((string) $row->exception),
                ...$this->payloadSummary((string) $row->payload),
            ])
            ->values()
            ->all();
    }

    private function failedUuids(?string $queue): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')
            ->when($queue, fn ($query) => $query->where('queue', $queue))
            ->pluck('uuid')
            ->map(fn ($uuid) => (string) $uuid)
            ->all();
    }

    private function failedUuidFor(string $id): ?string
    {
        if (! Schema::hasTable('failed_jobs')) {
            return null;
        }

        return DB::table('failed_jobs')
            ->where(fn ($query) => $query->where('id', $id)->orWhere('uuid', $id))
            ->value('uuid');
    }

    private function runtimeQueueSize(string $queue): ?int
    {
        try {
            return Queue::size($queue);
        } catch (Throwable) {
            return null;
        }
    }

    private function payloadSummary(string $payload): array
    {
        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            return ['job' => 'Unknown job', 'display_name' => 'Unknown job', 'uuid' => null];
        }

        $display = (string) ($decoded['displayName'] ?? $decoded['job'] ?? data_get($decoded, 'data.commandName') ?? 'Queued job');

        return [
            'uuid' => $decoded['uuid'] ?? null,
            'job' => (string) ($decoded['job'] ?? $display),
            'display_name' => $display,
            'max_tries' => $decoded['maxTries'] ?? null,
        ];
    }

    private function firstLine(string $value): string
    {
        $line = trim(strtok($value, "\n") ?: $value);

        return mb_substr($line, 0, 500);
    }

    private function fromTimestamp(int $timestamp): ?string
    {
        return $timestamp > 0 ? Carbon::createFromTimestamp($timestamp)->toIso8601String() : null;
    }

    private function recommendations(string $state, string $connection, int $backlog, int $failed): array
    {
        $items = [];

        if ($connection !== 'redis') {
            $items[] = 'Move production workers to Redis queue for lower latency and better backlog handling.';
        }

        if (! class_exists(\Laravel\Horizon\Horizon::class)) {
            $items[] = 'Install Laravel Horizon to supervise Redis queues and expose worker telemetry.';
        }

        if ($state === 'blocked') {
            $items[] = "Backlog is {$backlog}; start or scale workers before live automation.";
        }

        if ($failed > 0) {
            $items[] = "Review {$failed} failed job(s), then retry or forget only after checking the exception.";
        }

        return $items;
    }
}
