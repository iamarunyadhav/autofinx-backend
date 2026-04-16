<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatioBotEvent extends BaseModel
{
    protected $casts = [
        'context'     => 'array',
        'occurred_at' => 'datetime',
    ];

    public const EVENT_STARTED                = 'started';
    public const EVENT_PAUSED                 = 'paused';
    public const EVENT_RESUMED                = 'resumed';
    public const EVENT_STOPPED                = 'stopped';
    public const EVENT_BLOCKED                = 'blocked';
    public const EVENT_UNBLOCKED              = 'unblocked';
    public const EVENT_KILL_SWITCH            = 'kill_switch_activated';
    public const EVENT_COOLDOWN_ENTERED       = 'cooldown_entered';
    public const EVENT_COOLDOWN_EXITED        = 'cooldown_exited';
    public const EVENT_ORDER_FAILURE          = 'order_failure';
    public const EVENT_RECONCILIATION_MISMATCH = 'reconciliation_mismatch';
    public const EVENT_FEED_STALE             = 'feed_stale';
    public const EVENT_MANUAL_BUY_ANCHORED    = 'manual_buy_anchored';

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }
}
