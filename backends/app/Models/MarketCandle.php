<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketCandle extends BaseModel
{
    protected $casts = [
        'opens_at' => 'datetime',
    ];

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }
}
