<?php

namespace App\Models;

class ExchangeSymbolMetadata extends BaseModel
{
    protected $casts = [
        'min_qty' => 'decimal:8',
        'max_qty' => 'decimal:8',
        'step_size' => 'decimal:8',
        'min_notional' => 'decimal:8',
        'tick_size' => 'decimal:8',
        'last_synced_at' => 'datetime',
        'raw_json' => 'array',
    ];
}
