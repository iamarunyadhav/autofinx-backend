<?php

namespace App\Domain\Ops;

use App\Models\SchedulerRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

class QueueControlService
{
    public function dashboard(int $limit = 25): array
    {
        $queues = $this->queueRows();
        $stalePruneConfig = $this->stalePruneConfig();
        $connection = (string) config('queue.default', 'database');
        $backlog = array_sum(array_column($queues, 'pending'));
        $excludedQueues = collect(config('ops.live_readiness.queue_backlog_excluded_queues', []))
            ->map(fn ($queue) => (string) $queue)
            ->filter()
            ->all();
        $blockingBacklog = array_sum(array_map(
            fn (array $row) => in_array($row['name'], $excludedQueues, true) ? 0 : (int) $row['pending'],
            $queues
        ));
        $failed = array_sum(array_column($queues, 'failed'));
        $warningThreshold = (int) config('ops.live_readiness.queue_backlog_warning', 100);
        $blockThreshold = (int) config('ops.live_readiness.queue_backlog_block', 500);
        $state = $blockingBacklog >= $blockThreshold ? 'blocked' : ($blockingBacklog >= $warningThreshold || $failed > 0 ? 'degraded' : 'healthy');

        return [
            'state' => $state,
            'connection' => $connection,
            'driver' => (string) config("queue.connections.{$connection}.driver", $connection),
            'backlog' => $backlog,
            'blocking_backlog' => $blockingBacklog,
            'failed' => $failed,
            'warning_threshold' => $warningThreshold,
            'block_threshold' => $blockThreshold,
            'excluded_queues' => $excludedQueues,
            'protected_queues' => $this->protectedQueues(),
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
                'topology' => 'three_worker_split',
                'supervisor_command' => 'sudo supervisorctl restart autofinx-queue:*',
                'horizon_command' => 'php artisan horizon',
                'profiles' => $this->workerProfiles(),
            ],
            'queues' => $queues,
            'pending_jobs' => $this->pendingJobs($limit),
            'failed_jobs' => $this->failedJobs($limit),
            'stale_prune' => [
                ...$stalePruneConfig,
                'candidates' => $this->stalePendingCandidates($stalePruneConfig['older_than_minutes'], $limit),
            ],
            'auto_maintenance' => $this->autoMaintenanceConfig(),
            'scheduler_runs' => SchedulerRun::query()
                ->orderByDesc('started_at')
                ->limit($limit)
                ->get(['id', 'command', 'status', 'started_at', 'finished_at', 'duration_ms'])
                ->values(),
            'recommendations' => $this->recommendations($state, $connection, $blockingBacklog, $failed),
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
        abort_if(in_array($queue, $this->protectedQueues(), true), 422, "Queue {$queue} is protected. Use stale prune or worker scaling instead.");

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

    public function pruneStalePending(?array $queues = null, ?int $olderThanMinutes = null): array
    {
        $config = $this->stalePruneConfig();
        abort_unless((bool) $config['enabled'], 403, 'Stale queue pruning is disabled on this server.');
        abort_unless(Schema::hasTable('jobs'), 422, 'Jobs table is not available.');

        $olderThanMinutes = $olderThanMinutes !== null && $olderThanMinutes === 0 ? 0 : max(1, (int) ($olderThanMinutes ?: $config['older_than_minutes']));
        $requestedQueues = collect($queues ?? [])
            ->map(fn ($queue) => trim((string) $queue))
            ->filter()
            ->values()
            ->all();
        $maxDelete = max(1, (int) $config['max_delete']);

        $rows = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('created_at', '<=', time() - ($olderThanMinutes * 60))
            ->when($requestedQueues !== [], fn ($query) => $query->whereIn('queue', $requestedQueues))
            ->orderBy('created_at')
            ->limit($maxDelete)
            ->get();

        $deletedIds = [];
        $deletedByQueue = [];
        $deletedByJob = [];
        $skipped = [];

        foreach ($rows as $row) {
            $candidate = $this->pendingCleanupCandidate($row, $olderThanMinutes);
            if (! $candidate['prunable']) {
                $skipped[] = [
                    'id' => (string) $row->id,
                    'queue' => (string) $row->queue,
                    'job' => (string) $candidate['display_name'],
                    'reason' => (string) $candidate['prune_reason'],
                ];
                continue;
            }

            $deletedIds[] = $row->id;
            $queue = (string) $row->queue;
            $job = (string) $candidate['display_name'];
            $deletedByQueue[$queue] = ($deletedByQueue[$queue] ?? 0) + 1;
            $deletedByJob[$job] = ($deletedByJob[$job] ?? 0) + 1;
        }

        if ($deletedIds !== []) {
            DB::table('jobs')->whereIn('id', $deletedIds)->delete();
        }

        return [
            'action' => 'prune_stale_pending',
            'older_than_minutes' => $olderThanMinutes,
            'requested_queues' => $requestedQueues,
            'pruned' => count($deletedIds),
            'pruned_by_queue' => $deletedByQueue,
            'pruned_by_job' => $deletedByJob,
            'skipped' => array_slice($skipped, 0, 25),
            'message' => count($deletedIds) > 0
                ? 'Stale safe pending jobs were pruned.'
                : 'No stale safe pending jobs matched the request.',
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

    public function maintainBacklog(): array
    {
        $config = $this->autoMaintenanceConfig();
        if (! $config['enabled']) {
            return [
                'action' => 'maintain_backlog',
                'enabled' => false,
                'message' => 'Automatic backlog maintenance is disabled.',
            ];
        }

        $dashboard = $this->dashboard(25);
        $backlog = (int) ($dashboard['blocking_backlog'] ?? $dashboard['backlog'] ?? 0);
        if ($backlog < $config['trigger_backlog']) {
            return [
                'action' => 'maintain_backlog',
                'enabled' => true,
                'trigger_backlog' => $config['trigger_backlog'],
                'blocking_backlog' => $backlog,
                'message' => 'Backlog is below the automatic maintenance threshold.',
            ];
        }

        // Emergency mode: backlog > 500 — prune all safe jobs regardless of age (0 minutes)
        $emergencyThreshold = max($config['trigger_backlog'] * 5, 500);
        $isEmergency = $backlog >= $emergencyThreshold;
        $pruneAgeMinutes = $isEmergency ? 0 : $config['prune_older_than_minutes'];

        $prune = $this->pruneStalePending(null, $pruneAgeMinutes);

        // If emergency and still high, do a second pass on the normal age threshold
        $postPrune = $this->dashboard(25);
        $postPruneBacklog = (int) ($postPrune['blocking_backlog'] ?? $postPrune['backlog'] ?? 0);
        if ($isEmergency && $postPruneBacklog >= $config['trigger_backlog']) {
            $prune2 = $this->pruneStalePending(null, $config['prune_older_than_minutes']);
            $prune['pruned'] = ($prune['pruned'] ?? 0) + ($prune2['pruned'] ?? 0);
            $postPrune = $this->dashboard(25);
            $postPruneBacklog = (int) ($postPrune['blocking_backlog'] ?? $postPrune['backlog'] ?? 0);
        }

        $restarted = false;
        if (
            $config['restart_when_still_blocked']
            && ($postPrune['state'] ?? null) === 'blocked'
            && $this->restartCooldownPassed($config['restart_cooldown_minutes'])
        ) {
            $this->restartWorkers();
            Cache::put($this->restartCooldownCacheKey(), now()->toIso8601String(), now()->addMinutes($config['restart_cooldown_minutes']));
            $restarted = true;
        }

        return [
            'action' => 'maintain_backlog',
            'enabled' => true,
            'emergency' => $isEmergency,
            'trigger_backlog' => $config['trigger_backlog'],
            'blocking_backlog_before' => $backlog,
            'blocking_backlog_after' => $postPruneBacklog,
            'state_before' => $dashboard['state'] ?? null,
            'state_after' => $postPrune['state'] ?? null,
            'prune' => $prune,
            'workers_restarted' => $restarted,
            'message' => $isEmergency
                ? 'Emergency backlog maintenance: pruned all safe-queue jobs regardless of age.'
                : ($restarted
                    ? 'Automatic backlog maintenance pruned safe stale jobs and sent a worker restart signal.'
                    : 'Automatic backlog maintenance completed.'),
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

        $staleMinutes = $this->stalePruneConfig()['older_than_minutes'];

        return DB::table('jobs')
            ->orderBy('available_at')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(function ($row) use ($staleMinutes) {
                $candidate = $this->pendingCleanupCandidate($row, $staleMinutes);

                return [
                    'id' => (string) $row->id,
                    'queue' => (string) $row->queue,
                    'attempts' => (int) $row->attempts,
                    'reserved' => $row->reserved_at !== null,
                    'available_at' => $this->fromTimestamp((int) $row->available_at),
                    'created_at' => $this->fromTimestamp((int) $row->created_at),
                    'age_seconds' => $candidate['age_seconds'],
                    'prunable' => $candidate['prunable'],
                    'prune_reason' => $candidate['prune_reason'],
                    ...$candidate['payload'],
                ];
            })
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
            $items[] = 'Use the 3-worker split so live execution queues stay separate from analytics and safe monitoring backlog.';
        }

        if ($failed > 0) {
            $items[] = "Review {$failed} failed job(s), then retry or forget only after checking the exception.";
        }

        return $items;
    }

    private function workerProfiles(): array
    {
        return collect(config('queue_control.worker_profiles', []))
            ->map(function (array $profile, string $key) {
                return [
                    'key' => $key,
                    'label' => (string) ($profile['label'] ?? $key),
                    'purpose' => (string) ($profile['purpose'] ?? ''),
                    'queues' => array_values(array_map('strval', (array) ($profile['queues'] ?? []))),
                    'command' => (string) ($profile['command'] ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    private function stalePendingCandidates(int $olderThanMinutes, int $limit): array
    {
        if (! Schema::hasTable('jobs')) {
            return [
                'older_than_minutes' => $olderThanMinutes,
                'prunable_total' => 0,
                'sample' => [],
            ];
        }

        $rows = DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('created_at', '<=', time() - ($olderThanMinutes * 60))
            ->orderBy('created_at')
            ->limit(max(1, min(100, $limit)))
            ->get();

        $sample = [];
        $prunableTotal = 0;

        foreach ($rows as $row) {
            $candidate = $this->pendingCleanupCandidate($row, $olderThanMinutes);
            if ($candidate['prunable']) {
                $prunableTotal++;
            }

            $sample[] = [
                'id' => (string) $row->id,
                'queue' => (string) $row->queue,
                'display_name' => $candidate['display_name'],
                'age_seconds' => $candidate['age_seconds'],
                'prunable' => $candidate['prunable'],
                'prune_reason' => $candidate['prune_reason'],
            ];
        }

        return [
            'older_than_minutes' => $olderThanMinutes,
            'prunable_total' => $prunableTotal,
            'sample' => $sample,
        ];
    }

    private function pendingCleanupCandidate(object $row, int $olderThanMinutes): array
    {
        $payload = $this->payloadSummary((string) $row->payload);
        $displayName = (string) ($payload['display_name'] ?? 'Unknown job');
        $queue = (string) $row->queue;
        $ageSeconds = max(0, time() - (int) $row->created_at);
        $config = $this->stalePruneConfig();

        $prunable = false;
        $reason = 'queue_not_allowlisted';

        if ($row->reserved_at !== null) {
            $reason = 'reserved_job';
        } elseif ($olderThanMinutes > 0 && $ageSeconds < ($olderThanMinutes * 60)) {
            $reason = 'not_stale_enough';
        } elseif (in_array($queue, $config['safe_queues'], true)) {
            $prunable = true;
            $reason = 'safe_queue';
        } elseif (in_array($displayName, $config['safe_job_classes'], true)) {
            $prunable = true;
            $reason = 'safe_job_class';
        } elseif (in_array($queue, $this->protectedQueues(), true)) {
            $reason = 'protected_queue';
        }

        return [
            'display_name' => $displayName,
            'age_seconds' => $ageSeconds,
            'prunable' => $prunable,
            'prune_reason' => $reason,
            'payload' => $payload,
        ];
    }

    private function protectedQueues(): array
    {
        return collect(config('queue_control.protected_queues', []))
            ->map(fn ($queue) => trim((string) $queue))
            ->filter()
            ->values()
            ->all();
    }

    private function stalePruneConfig(): array
    {
        $config = (array) config('queue_control.stale_prune', []);

        return [
            'enabled' => (bool) ($config['enabled'] ?? true),
            'older_than_minutes' => max(1, (int) ($config['older_than_minutes'] ?? 30)),
            'max_delete' => max(1, (int) ($config['max_delete'] ?? 250)),
            'safe_queues' => collect((array) ($config['safe_queues'] ?? []))
                ->map(fn ($queue) => trim((string) $queue))
                ->filter()
                ->values()
                ->all(),
            'safe_job_classes' => collect((array) ($config['safe_job_classes'] ?? []))
                ->map(fn ($job) => trim((string) $job))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function autoMaintenanceConfig(): array
    {
        $config = (array) config('queue_control.auto_maintenance', []);

        return [
            'enabled' => (bool) ($config['enabled'] ?? true),
            'trigger_backlog' => max(1, (int) ($config['trigger_backlog'] ?? 300)),
            'prune_older_than_minutes' => max(1, (int) ($config['prune_older_than_minutes'] ?? 30)),
            'restart_when_still_blocked' => (bool) ($config['restart_when_still_blocked'] ?? false),
            'restart_cooldown_minutes' => max(1, (int) ($config['restart_cooldown_minutes'] ?? 15)),
        ];
    }

    private function restartCooldownPassed(int $minutes): bool
    {
        $lastRestart = Cache::get($this->restartCooldownCacheKey());
        if (! is_string($lastRestart) || $lastRestart === '') {
            return true;
        }

        try {
            return Carbon::parse($lastRestart)->lte(now()->subMinutes($minutes));
        } catch (Throwable) {
            return true;
        }
    }

    private function restartCooldownCacheKey(): string
    {
        return 'queue-control:last-auto-restart-at';
    }
}
