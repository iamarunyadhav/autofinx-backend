<?php

namespace App\Models;

class Plan extends BaseModel
{
    protected $casts = [
        'features' => 'array',
    ];
}
