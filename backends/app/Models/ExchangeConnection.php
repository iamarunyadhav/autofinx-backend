<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeConnection extends BaseModel
{
    protected $casts = [
        'permissions' => 'array',
        'last_synced_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
