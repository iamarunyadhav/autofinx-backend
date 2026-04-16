<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotLog extends BaseModel
{
    protected $casts = [
        'meta_json' => 'array',
        'ts' => 'datetime',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
