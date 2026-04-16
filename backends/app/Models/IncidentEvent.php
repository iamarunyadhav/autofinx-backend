<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentEvent extends BaseModel
{
    protected $casts = [
        'metadata_json' => 'array',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
