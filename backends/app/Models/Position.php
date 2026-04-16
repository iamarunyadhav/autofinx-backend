<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Position extends BaseModel
{
    protected $casts = [
        'qty' => 'decimal:8',
        'avg_entry' => 'decimal:8',
        'unrealized_pnl' => 'decimal:8',
        'realized_pnl' => 'decimal:8',
        'last_mark_price' => 'decimal:8',
        'leverage' => 'decimal:4',
        'liquidation_price' => 'decimal:8',
        'liquidation_buffer_pct' => 'decimal:4',
        'mark_price_synced_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    public function exitState(): HasOne
    {
        return $this->hasOne(PositionExitState::class);
    }
}
