<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ReleaseRecord extends BaseModel
{
    protected $casts = [
        'change_summary_json' => 'array',
        'metadata_json' => 'array',
        'released_at' => 'datetime',
        'verified_at' => 'datetime',
        'rolled_back_at' => 'datetime',
    ];

    public function postDeployChecks(): HasMany
    {
        return $this->hasMany(PostDeployCheck::class);
    }

    public function rollbackRecords(): HasMany
    {
        return $this->hasMany(RollbackRecord::class);
    }
}

