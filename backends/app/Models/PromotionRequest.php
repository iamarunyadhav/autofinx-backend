<?php

namespace App\Models;

class PromotionRequest extends BaseModel
{
    protected $casts = [
        'payload_json' => 'array',
    ];
}
