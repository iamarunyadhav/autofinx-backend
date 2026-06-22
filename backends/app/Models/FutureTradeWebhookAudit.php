<?php

namespace App\Models;

class FutureTradeWebhookAudit extends BaseModel
{
    protected $table = 'future_trade_webhook_audits';

    protected $casts = [
        'received_at' => 'datetime',
        'token_valid' => 'boolean',
        'raw_payload' => 'array',
        'price' => 'decimal:10',
        'bar_time' => 'datetime',
        'bar_confirmed' => 'boolean',
        'latency_from_bar_close_ms' => 'integer',
        'total_latency_ms' => 'integer',
        'context' => 'array',
    ];
}
