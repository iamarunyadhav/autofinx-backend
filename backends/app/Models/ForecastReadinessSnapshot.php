<?php

namespace App\Models;

/**
 * A single point-in-time verdict produced by
 * `App\Domain\RatioBot\ForecastProductionReadinessReport::generate()` for one
 * (bot_type, bot_instance_id, horizon_seconds) scope, written by the
 * `forecast:evaluate-readiness` scheduled command. Read-only reporting
 * record -- never written to or read from any live trading/execution path.
 */
class ForecastReadinessSnapshot extends BaseModel
{
    protected $casts = [
        'bot_type' => 'string',
        'bot_instance_id' => 'string',
        'horizon_seconds' => 'integer',
        'status' => 'string',
        'reasons' => 'array',
        'report_json' => 'array',
        'computed_at' => 'datetime',
    ];
}
