<?php

namespace App\Models;

class ModuleSetting extends BaseModel
{
    protected $casts = [
        'settings_json' => 'array',
    ];
}
