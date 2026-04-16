<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDecisionEvent extends BaseModel
{
    protected $casts = [
        'payload_json' => 'array',
    ];

    public function decision(): BelongsTo
    {
        return $this->belongsTo(AiDecision::class, 'ai_decision_id');
    }
}
