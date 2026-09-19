<?php

namespace App\Models;

class NexusRun extends BaseModel
{
    protected $casts = ['summary' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
}
