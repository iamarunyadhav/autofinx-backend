<?php

namespace App\Models;

class RobotTradeAudit extends BaseModel
{
    protected $table = 'robot_trade_audits';

    protected $casts = [
        'candle_open_time' => 'datetime',
        'candle_open_price' => 'decimal:10',
        'current_price' => 'decimal:10',
        'candle_body_size' => 'decimal:10',
        'upper_wick' => 'decimal:10',
        'lower_wick' => 'decimal:10',
        'noisy_candle' => 'boolean',
        'confidence_score' => 'decimal:4',
        'context' => 'array',
    ];
}
