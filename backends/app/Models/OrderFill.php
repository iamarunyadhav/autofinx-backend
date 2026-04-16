<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFill extends BaseModel
{
    protected $casts = [
        'fill_price' => 'decimal:8',
        'fill_qty' => 'decimal:8',
        'fee' => 'decimal:8',
        'fill_ts' => 'datetime',
        'raw_json' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
