<?php

namespace App\Domain\Ml\Training;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Depth and recent failures of the ml_training queue. The connection really is
 * driver "database" (config/queue.php), so depth is a query on the jobs table
 * filtered by queue name (indexed). Any other driver reports pending only and
 * reserved as null; a missing table reports nulls, never an error.
 */
class TrainingQueueStats
{
    public function connection(): string
    {
        return (string) config('ml.training.connection', 'ml_training');
    }

    public function queueName(): string
    {
        return (string) config('ml.training.queue', 'ml_training');
    }

    /** @return array{pending: int|null, reserved: int|null, failed: int} */
    public function summary(): array
    {
        $pending = null;
        $reserved = null;
        try {
            $config = (array) config('queue.connections.'.$this->connection(), []);
            if (($config['driver'] ?? null) === 'database') {
                $jobs = DB::connection($config['connection'] ?? null)->table((string) ($config['table'] ?? 'jobs'))->where('queue', $this->queueName());
                $pending = (clone $jobs)->whereNull('reserved_at')->count();
                $reserved = (clone $jobs)->whereNotNull('reserved_at')->count();
            } else {
                $pending = (int) Queue::connection($this->connection())->size($this->queueName());
            }
        } catch (Throwable) {
            // Queue storage unavailable: show unknown rather than fail the page.
        }

        return ['pending' => $pending, 'reserved' => $reserved, 'failed' => $this->failedQuery()?->where('failed_at', '>=', Carbon::now()->subDay()->format('Y-m-d H:i:s'))->count() ?? 0];
    }

    /** @return list<array<string, mixed>> */
    public function failedRecent(int $limit = 10): array
    {
        try {
            $rows = $this->failedQuery()?->orderByDesc('id')->limit($limit)->get(['id', 'payload', 'exception', 'failed_at']) ?? collect();
        } catch (Throwable) {
            return [];
        }
        $recorder = app(TrainingRunRecorder::class);

        return $rows->map(function ($row) use ($recorder): array {
            $decoded = json_decode((string) $row->payload, true);
            $name = (string) ($decoded['displayName'] ?? 'job');
            $command = (string) data_get($decoded, 'data.command', '');
            $first = trim((string) strtok((string) $row->exception, "\n"));
            $item = ['id' => (string) $row->id, 'failed_at' => Carbon::parse((string) $row->failed_at)->toIso8601String()];
            // The payload is a serialized job: read the two public props with a
            // pattern, never unserialize() stored data.
            if (preg_match('/"symbol";s:\d+:"([A-Z0-9]+)"/', $command, $m)) {
                $item['symbol'] = $m[1];
            }
            if (preg_match('/"horizonMinutes";i:(\d+)/', $command, $m)) {
                $item['horizon_minutes'] = (int) $m[1];
            }
            $item['summary'] = mb_substr($recorder->redact(class_basename($name).($first !== '' ? ': '.$first : '')), 0, 240);

            return $item;
        })->all();
    }

    private function failedQuery(): ?\Illuminate\Database\Query\Builder
    {
        try {
            $failed = (array) config('queue.failed', []);

            return DB::connection($failed['database'] ?? null)->table((string) ($failed['table'] ?? 'failed_jobs'))->where('queue', $this->queueName());
        } catch (Throwable) {
            return null;
        }
    }
}
