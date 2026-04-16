<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketTick extends BaseModel
{
    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
        'price' => 'decimal:8',
        'bid' => 'decimal:8',
        'ask' => 'decimal:8',
        'volume' => 'decimal:8',
    ];

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }
}
