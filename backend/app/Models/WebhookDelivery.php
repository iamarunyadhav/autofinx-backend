<?php

namespace App\Models;

class WebhookDelivery extends BaseModel
{
    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
    ];
}
