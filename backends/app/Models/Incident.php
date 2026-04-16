<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends BaseModel
{
    protected $casts = [
        'affected_entities_json' => 'array',
        'context_json' => 'array',
        'detected_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'mitigated_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_activity_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function runbook(): BelongsTo
    {
        return $this->belongsTo(Runbook::class, 'runbook_key', 'key');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(IncidentAction::class);
    }
}
