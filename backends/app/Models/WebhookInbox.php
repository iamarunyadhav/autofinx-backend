<?php

namespace App\Models;

class WebhookInbox extends BaseModel
{
    protected $table = 'webhooks_inbox';

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
