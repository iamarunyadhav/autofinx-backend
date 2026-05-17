<?php

namespace App\Models;

class FutureTradeRuntimeInstance extends BaseModel
{
    protected $table = 'future_trade_runtime_instances';

    protected $casts = [
        'pid' => 'integer',
        'started_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'stopped_at' => 'datetime',
        'meta' => 'array',
    ];

    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_STOPPED = 'STOPPED';
    public const STATUS_DEGRADED = 'DEGRADED';
}
