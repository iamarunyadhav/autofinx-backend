<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskProfile extends BaseModel
{
    protected $casts = [
        'is_default' => 'boolean',
        'rules' => 'array',
        'max_daily_loss' => 'decimal:8',
        'max_drawdown_pct' => 'decimal:4',
        'max_open_positions' => 'integer',
        'max_slippage_pct_default' => 'decimal:4',
        'circuit_breaker_vol_threshold' => 'decimal:4',
        'cooldown_sec_after_stop' => 'integer',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
