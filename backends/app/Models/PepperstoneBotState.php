<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PepperstoneBotState extends BaseModel
{
    protected $casts = [
        'active_levels_count' => 'integer',
        'total_exposure_lots' => 'decimal:4',
        'basket_avg_price' => 'decimal:6',
        'last_evaluated_at' => 'datetime',
    ];

    public const STATUS_IDLE = 'idle';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_STOPPED = 'stopped';

    public function config(): BelongsTo
    {
        return $this->belongsTo(PepperstoneBotConfig::class, 'pepperstone_bot_config_id');
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isIdle(): bool
    {
        return $this->status === self::STATUS_IDLE;
    }
}
