<?php

namespace App\Models;

class PortfolioTarget extends BaseModel
{
    protected $primaryKey = 'workspace_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $casts = [
        'targets_json' => 'array',
        'enabled' => 'boolean',
        'last_rebalanced_at' => 'datetime',
    ];
}
