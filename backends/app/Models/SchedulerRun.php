<?php

namespace App\Models;

class SchedulerRun extends BaseModel
{
    protected $casts = [
        'meta' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
