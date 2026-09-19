<?php

namespace App\Models;

class NexusModelCall extends BaseModel
{
    protected $casts = ['result' => 'array', 'deep' => 'boolean', 'reserved_usd' => 'float'];
}
