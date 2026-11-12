<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickTradeManualProtectionPair extends BaseModel
{
    public const PENDING = 'PENDING';
    public const ARMED = 'ARMED';
    public const ACTIVE = 'ACTIVE';
    public const HIT = 'HIT';
    public const CANCELLED = 'CANCELLED';

    protected $table = 'quick_trade_manual_protection_pairs';

    protected $casts = [
        'level' => 'integer', 'tp_value' => 'decimal:10', 'tp_price' => 'decimal:10',
        'sl_value' => 'decimal:10', 'sl_price' => 'decimal:10', 'trailing_distance' => 'decimal:10',
        'peak_price' => 'decimal:10', 'trough_price' => 'decimal:10', 'active_stop_price' => 'decimal:10',
        'activated_at' => 'datetime', 'updated_stop_at' => 'datetime',
    ];

    public function position(): BelongsTo { return $this->belongsTo(QuickTradeManualPosition::class, 'position_id'); }
}
