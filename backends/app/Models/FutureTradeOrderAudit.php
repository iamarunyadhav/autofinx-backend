<?php

namespace App\Models;

class FutureTradeOrderAudit extends BaseModel
{
    protected $table = 'future_trade_order_audits';

    protected $casts = [
        'order_request_at' => 'datetime',
        'quantity' => 'decimal:10',
        'leverage' => 'decimal:2',
        'reduce_only' => 'boolean',
        'price' => 'decimal:10',
        'stop_loss' => 'decimal:10',
        'take_profit' => 'decimal:10',
        'trailing_stop' => 'decimal:10',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'context' => 'array',
    ];
}
