<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioSnapshot extends BaseModel
{
    protected $casts = [
        'positions' => 'array',
        'metrics' => 'array',
        'captured_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
