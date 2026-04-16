<?php

namespace App\Models;

class OptimizerRun extends BaseModel
{
    protected $casts = [
        'inputs_json' => 'array',
        'outputs_json' => 'array',
    ];
}
