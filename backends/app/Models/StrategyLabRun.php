<?php

namespace App\Models;

use App\Casts\UtcDateTime;

/** One async Quick / Deep test of a candidate version (development data only, research only). */
class StrategyLabRun extends BaseModel
{
    public const QUICK = 'QUICK';

    public const DEEP = 'DEEP';

    public const QUEUED = 'QUEUED';

    public const RUNNING = 'RUNNING';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    public const ACTIVE = [self::QUEUED, self::RUNNING];

    public const PHASES = ['queued', 'preparing_data', 'running', 'scoring', 'done'];

    protected $table = 'strategy_lab_runs';

    protected $casts = [
        'progress_pct' => 'float', 'summary' => 'array',
        'queued_at' => UtcDateTime::class, 'started_at' => UtcDateTime::class, 'heartbeat_at' => UtcDateTime::class,
        'finished_at' => UtcDateTime::class, 'cancel_requested_at' => UtcDateTime::class,
        'created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class,
    ];

    public static function utcNow(): string
    {
        return now('UTC')->format('Y-m-d H:i:s');
    }

    public function freshTimestamp()
    {
        return \Illuminate\Support\Facades\Date::now('UTC');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }
}
