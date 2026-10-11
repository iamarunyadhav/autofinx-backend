<?php

namespace App\Models;

class StrategyLabShadowSignal extends BaseModel
{
    protected $casts = [
        'entry' => 'float', 'sl' => 'float', 'tp1' => 'float', 'tp2' => 'float',
        'time_close_ms' => 'integer', 'latency_ms' => 'integer', 'payload' => 'array',
        'gross_return_pct' => 'float', 'fees_pct' => 'float', 'funding_pct' => 'float',
        'net_return_pct' => 'float', 'evaluated_at' => 'datetime', 'received_at' => 'datetime',
    ];
}
