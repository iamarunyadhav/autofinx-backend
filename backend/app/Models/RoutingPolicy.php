<?php

namespace App\Models;

class RoutingPolicy extends BaseModel
{
    protected $casts = [
        'allow_split_orders' => 'boolean',
    ];
}
