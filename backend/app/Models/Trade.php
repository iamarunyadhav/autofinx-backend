<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Trade extends BaseModel
{
    protected $casts = [
        'executed_at' => 'datetime',
        'ts' => 'datetime',
        'meta' => 'array',
        'qty' => 'decimal:8',
        'avg_price' => 'decimal:8',
        'fees' => 'decimal:8',
        'realized_pnl' => 'decimal:8',
        'fx_used_usd_lkr' => 'decimal:8',
        'pnl_lkr' => 'decimal:8',
        'spread_pct' => 'decimal:4',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
