<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyParam extends BaseModel
{
    protected $casts = [
        'value' => 'array',
    ];

    public function instance(): BelongsTo
    {
        return $this->belongsTo(StrategyInstance::class, 'instance_id');
    }
}
