<?php

namespace App\Models;

class EventStore extends BaseModel
{
    protected $table = 'event_store';

    protected $casts = [
        'payload_json' => 'array',
        'occurred_at' => 'datetime',
    ];
}
