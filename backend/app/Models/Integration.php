<?php

namespace App\Models;

class Integration extends BaseModel
{
    protected $casts = [
        'config_json' => 'array',
    ];
}
