<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForexGridBotEvent extends BaseModel
{
    public $timestamps = false;

    protected $casts = [
        'context_json' => 'array',
        'created_at' => 'datetime',
    ];

    public const EVENT_STARTED = 'started';
    public const EVENT_PAUSED = 'paused';
    public const EVENT_STOPPED = 'stopped';
    public const EVENT_LEVEL_OPENED = 'level_opened';
    public const EVENT_BLOCKED = 'blocked';
    public const EVENT_BASKET_CLOSED = 'basket_closed';
    public const EVENT_FEED_ERROR = 'feed_error';
    public const EVENT_KILL_SWITCH = 'kill_switch_activated';

    public function config(): BelongsTo
    {
        return $this->belongsTo(ForexGridBotConfig::class, 'forex_grid_bot_config_id');
    }
}
