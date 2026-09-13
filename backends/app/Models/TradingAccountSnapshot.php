<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradingAccountSnapshot extends BaseModel
{
    protected $casts = [
        'equity' => 'decimal:4',
        'used_margin' => 'decimal:4',
        'free_margin' => 'decimal:4',
        'balance' => 'decimal:4',
        'synced_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ExchangeConnection::class, 'connection_id');
    }
}
