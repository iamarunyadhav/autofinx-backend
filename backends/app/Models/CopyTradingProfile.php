<?php

namespace App\Models;

class CopyTradingProfile extends BaseModel
{
    protected $casts = [
        'allowed_symbols' => 'array',
        'paused' => 'boolean',
    ];
}
