<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fast2XTrade extends BaseModel
{
    protected $table = 'fast2x_trades';

    protected $casts = [
        'entry_price' => 'decimal:8',
        'exit_price' => 'decimal:8',
        'quantity' => 'decimal:8',
        'gross_pnl' => 'decimal:8',
        'fees' => 'decimal:8',
        'slippage' => 'decimal:8',
        'net_pnl' => 'decimal:8',
        'meta' => 'array',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(Fast2XConfig::class, 'fast2x_config_id');
    }
}
