<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Runbook extends BaseModel
{
    protected $casts = [
        'steps_json' => 'array',
        'is_active' => 'boolean',
        'is_builtin' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
