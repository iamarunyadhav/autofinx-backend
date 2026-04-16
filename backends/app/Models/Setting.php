<?php

namespace App\Models;

class Setting extends BaseModel
{
    protected $casts = [
        'value_json' => 'array',
        'encrypted' => 'boolean',
    ];
}
