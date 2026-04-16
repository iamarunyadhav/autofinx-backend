<?php

namespace App\Models;

class Webhook extends BaseModel
{
    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
    ];
}
