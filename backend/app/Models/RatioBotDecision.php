<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatioBotDecision extends BaseModel
{
    protected $casts = [
        'evaluated_price'       => 'decimal:8',
        'anchor_price'          => 'decimal:8',
        'computed_ratio'        => 'decimal:6',
        'threshold_used'        => 'decimal:6',
        'executed_qty'          => 'decimal:8',
        'executed_price'        => 'decimal:8',
        'metadata'              => 'array',
        'decided_at'            => 'datetime',
        'executed_at'           => 'datetime',
    ];

    public const DECISION_BUY      = 'BUY';
    public const DECISION_SELL     = 'SELL';
    public const DECISION_HOLD     = 'HOLD';
    public const DECISION_PAUSE    = 'PAUSE';
    public const DECISION_BLOCK    = 'BLOCK';
    public const DECISION_COOLDOWN = 'COOLDOWN';

    public const EXEC_PENDING           = 'pending';
    public const EXEC_SUBMITTED         = 'submitted';
    public const EXEC_FILLED            = 'filled';
    public const EXEC_PARTIAL           = 'partially_filled';
    public const EXEC_FAILED            = 'failed';
    public const EXEC_SKIPPED           = 'skipped';
    public const EXEC_DUPLICATE_BLOCKED = 'duplicate_blocked';

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }

    public function isTrade(): bool
    {
        return in_array($this->decision, [self::DECISION_BUY, self::DECISION_SELL], true);
    }
}
