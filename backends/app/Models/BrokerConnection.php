<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrokerConnection extends BaseModel
{
    protected $casts = [
        'credentials' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
