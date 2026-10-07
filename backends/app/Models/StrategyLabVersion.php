<?php

namespace App\Models;

use App\Casts\UtcDateTime;

/** Immutable source version of a Strategy Lab candidate. The source is stored and shown as text, never executed. */
class StrategyLabVersion extends BaseModel
{
    protected $table = 'strategy_lab_versions';

    protected $casts = ['version' => 'integer', 'audit' => 'array', 'created_at' => UtcDateTime::class, 'updated_at' => UtcDateTime::class];

    public function freshTimestamp()
    {
        return \Illuminate\Support\Facades\Date::now('UTC');
    }
}
