<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Symbol extends BaseModel
{
    protected $casts = [
        'metadata' => 'array',
    ];

    public function candles(): HasMany
    {
        return $this->hasMany(MarketCandle::class);
    }
}
