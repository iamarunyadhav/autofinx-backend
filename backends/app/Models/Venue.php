<?php

namespace App\Models;

class Venue extends BaseModel
{
    protected $casts = [
        'meta_json' => 'array',
        'enabled' => 'boolean',
    ];
}
