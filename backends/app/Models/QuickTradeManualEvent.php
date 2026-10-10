<?php

namespace App\Models;

/** Append-only audit row for a manual SL/TP position. */
class QuickTradeManualEvent extends BaseModel
{
    public const UPDATED_AT = null;

    protected $table = 'quick_trade_manual_events';

    // Monotonic `seq` is the primary key (ordering); `id` is a public uuid.
    protected $primaryKey = 'seq';

    public $incrementing = true;

    protected $keyType = 'int';

    public function uniqueIds(): array
    {
        return ['id'];
    }

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];
}
