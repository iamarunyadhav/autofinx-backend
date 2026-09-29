<?php

namespace App\Models;

class PredictionFreshDatasetCandle extends BaseModel
{
    protected $casts = [
        'open_time' => 'datetime',
        'close_time' => 'datetime',
        'received_at' => 'datetime',
        'quality' => 'array',
        'open' => 'float',
        'high' => 'float',
        'low' => 'float',
        'close' => 'float',
        'volume' => 'float',
    ];
}
