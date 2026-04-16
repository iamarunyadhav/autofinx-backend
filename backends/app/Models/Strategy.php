<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Strategy extends BaseModel
{
    protected $casts = [
        'params_schema' => 'array',
    ];

    public function instances(): HasMany
    {
        return $this->hasMany(StrategyInstance::class);
    }
}
