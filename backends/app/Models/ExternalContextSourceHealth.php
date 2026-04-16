<?php

namespace App\Models;

class ExternalContextSourceHealth extends BaseModel
{
    protected $table = 'external_context_source_health';

    protected $casts = [
        'consecutive_failures' => 'integer',
        'last_ok_at' => 'datetime',
        'last_error_at' => 'datetime',
        'meta_json' => 'array',
    ];
}
