<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostIncidentReview extends BaseModel
{
    protected $casts = [
        'contributing_factors_json' => 'array',
        'follow_up_actions_json' => 'array',
        'evidence_json' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
