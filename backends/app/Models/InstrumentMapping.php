<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentMapping extends BaseModel
{
    protected $casts = [
        'tick_size' => 'decimal:8',
        'tick_value' => 'decimal:8',
        'contract_size' => 'decimal:4',
        'min_volume' => 'decimal:4',
        'volume_step' => 'decimal:4',
        'margin_requirement' => 'decimal:6',
        'discovered_at' => 'datetime',
    ];

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class, 'instrument_id');
    }
}
