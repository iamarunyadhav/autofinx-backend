<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VenueConnection extends BaseModel
{
    protected $casts = [
        'sandbox_mode' => 'boolean',
        'enabled' => 'boolean',
        'status_json' => 'array',
    ];

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }
}
