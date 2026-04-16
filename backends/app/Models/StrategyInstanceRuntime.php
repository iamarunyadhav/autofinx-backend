<?php

namespace App\Models;

class StrategyInstanceRuntime extends BaseModel
{
    protected $table = 'strategy_instance_runtime';

    protected $fillable = [
        'id',
        'strategy_instance_id',
        'last_evaluated_at',
        'last_trade_at',
        'trades_in_current_hour',
        'trades_in_current_day',
        'hour_bucket',
        'day_bucket',
    ];

    protected $casts = [
        'last_evaluated_at' => 'datetime',
        'last_trade_at' => 'datetime',
    ];
}
