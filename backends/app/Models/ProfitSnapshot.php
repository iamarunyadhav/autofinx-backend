<?php

namespace App\Models;

class ProfitSnapshot extends BaseModel
{
    protected $casts = [
        'per_strategy_json' => 'array',
        'date_bucket' => 'date',
    ];
}
