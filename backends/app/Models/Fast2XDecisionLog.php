<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fast2XDecisionLog extends BaseModel
{
    protected $table = 'fast2x_decision_logs';

    protected $casts = [
        'entry_price' => 'decimal:8',
        'current_price' => 'decimal:8',
        'net_pnl_percent' => 'decimal:6',
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(Fast2XConfig::class, 'fast2x_config_id');
    }
}
