<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatioBotReconciliationLog extends BaseModel
{
    protected $casts = [
        'snapshot' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }
}
