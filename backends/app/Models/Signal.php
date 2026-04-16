<?php

namespace App\Models;

class Signal extends BaseModel
{
    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];
}
