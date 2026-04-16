<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostDeployCheck extends BaseModel
{
    protected $casts = [
        'checklist_json' => 'array',
        'evidence_json' => 'array',
        'checked_at' => 'datetime',
    ];

    public function releaseRecord(): BelongsTo
    {
        return $this->belongsTo(ReleaseRecord::class);
    }
}

