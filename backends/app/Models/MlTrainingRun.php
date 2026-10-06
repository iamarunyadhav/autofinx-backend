<?php

namespace App\Models;

use App\Casts\UtcDateTime;

/**
 * One training request (symbol x horizon x type) and its live state. Shadow
 * ML control plane only: nothing here can reach an order path. Ids are UUIDv7
 * (HasUuids), so id order is creation order. Instants are UTC wall time.
 */
class MlTrainingRun extends BaseModel
{
    public const QUEUED = 'QUEUED';

    public const RUNNING = 'RUNNING';

    public const FINISHED = 'FINISHED';

    public const FAILED = 'FAILED';

    public const STOPPED = 'STOPPED';

    public const INTERRUPTED = 'INTERRUPTED';

    public const ACTIVE = [self::QUEUED, self::RUNNING];

    public const TERMINAL = [self::FINISHED, self::FAILED, self::STOPPED, self::INTERRUPTED];

    public const SOURCE_UI = 'ui';

    public const SOURCE_SCHEDULER = 'scheduler';

    public const SOURCE_CLI = 'cli';

    public const PHASES = ['queued', 'exporting', 'fitting', 'evaluating', 'promoting', 'done'];

    protected $table = 'ml_training_runs';

    protected $casts = [
        'horizon_minutes' => 'integer', 'progress_pct' => 'float', 'attempt' => 'integer', 'max_attempts' => 'integer',
        'worker_pid' => 'integer', 'rows_exported' => 'integer',
        'next_retry_at' => UtcDateTime::class, 'queued_at' => UtcDateTime::class, 'started_at' => UtcDateTime::class,
        'heartbeat_at' => UtcDateTime::class, 'finished_at' => UtcDateTime::class, 'cancel_requested_at' => UtcDateTime::class,
        'created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class,
    ];

    /** UTC wall-time string for query-builder writes (bulk updates bypass casts). */
    public static function utcNow(int $offsetSeconds = 0): string
    {
        return now('UTC')->addSeconds($offsetSeconds)->format('Y-m-d H:i:s');
    }

    /** Eloquent timestamps are written as UTC wall time too (the date path ignores the cast for created_at/updated_at). */
    public function freshTimestamp()
    {
        return \Illuminate\Support\Facades\Date::now('UTC');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
