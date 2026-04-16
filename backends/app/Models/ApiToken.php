<?php

namespace App\Models;

class ApiToken extends BaseModel
{
    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
    ];
}
