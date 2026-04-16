<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StrategyInstance extends BaseModel
{
    protected $casts = [
        'performance' => 'array',
    ];

    protected $fillable = [
        'id',
        'workspace_id',
        'strategy_id',
        'name',
        'mode',
        'status',
        'performance',
        'connection_id',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ExchangeConnection::class, 'connection_id');
    }

    public function params(): HasMany
    {
        return $this->hasMany(StrategyParam::class, 'instance_id');
    }

    public function riskProfile(): HasMany
    {
        return $this->hasMany(InstanceRiskProfile::class, 'instance_id');
    }

    public function runtime(): BelongsTo
    {
        return $this->belongsTo(StrategyInstanceRuntime::class, 'id', 'strategy_instance_id');
    }
}
