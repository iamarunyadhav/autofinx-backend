<?php

namespace App\Models;

class OrderRoute extends BaseModel
{
    protected $casts = [
        'reason_json' => 'array',
        'quote_json' => 'array',
    ];
}
