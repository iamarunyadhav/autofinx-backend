<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StakingPosition extends BaseModel
{
    protected $casts = [
        'meta' => 'array',
    ];

    protected $fillable = [
        'id',
        'workspace_id',
        'asset',
        'principal',
        'apy',
        'accrued',
        'status',
        'started_at',
        'ends_at',
        'meta',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
