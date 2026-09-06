<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatioBotRecoverySlot extends BaseModel
{
    protected $casts = [
        'quote_balance' => 'decimal:8',
        'base_balance' => 'decimal:8',
        'target_cap_quote_equiv' => 'decimal:8',
        'last_topped_up_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }
}
