<?php

namespace App\Models;

class FeatureFlag extends BaseModel
{
    public $timestamps = true;

    const UPDATED_AT = 'updated_at';

    const CREATED_AT = 'created_at';

    protected $casts = [
        'enabled' => 'boolean',
        'default_value' => 'boolean',
    ];
}
