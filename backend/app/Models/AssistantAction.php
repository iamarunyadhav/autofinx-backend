<?php

namespace App\Models;

class AssistantAction extends BaseModel
{
    protected $casts = [
        'request_json' => 'array',
        'preview_json' => 'array',
        'requires_2fa' => 'boolean',
        'approved_at' => 'datetime',
        'executed_at' => 'datetime',
    ];
}
