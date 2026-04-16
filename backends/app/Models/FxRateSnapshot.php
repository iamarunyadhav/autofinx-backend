<?php

namespace App\Models;

class FxRateSnapshot extends BaseModel
{
    protected $casts = [
        'rate' => 'decimal:8',
        'ts' => 'datetime',
    ];
}

