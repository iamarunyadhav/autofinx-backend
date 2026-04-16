<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RollbackRecord extends BaseModel
{
    protected $casts = [
        'details_json' => 'array',
        'evidence_json' => 'array',
        'requested_at' => 'datetime',
        'executed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function releaseRecord(): BelongsTo
    {
        return $this->belongsTo(ReleaseRecord::class);
    }
}

