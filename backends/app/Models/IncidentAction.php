<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentAction extends BaseModel
{
    protected $casts = [
        'metadata_json' => 'array',
        'executed_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
