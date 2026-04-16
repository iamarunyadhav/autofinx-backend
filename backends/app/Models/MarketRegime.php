<?php

namespace App\Models;

class MarketRegime extends BaseModel
{
    protected $casts = [
        'detected_at' => 'datetime',
        'meta_json' => 'array',
    ];
}
