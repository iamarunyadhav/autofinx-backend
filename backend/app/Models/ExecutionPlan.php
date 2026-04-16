<?php

namespace App\Models;

class ExecutionPlan extends BaseModel
{
    protected $casts = [
        'planned_routes_json' => 'array',
    ];
}
