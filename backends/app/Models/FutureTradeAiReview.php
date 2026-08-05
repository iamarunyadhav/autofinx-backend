<?php

namespace App\Models;

class FutureTradeAiReview extends BaseModel
{
    protected $table = 'future_trade_ai_reviews';

    protected $casts = [
        'review_date' => 'date',
        'stats_json' => 'array',
        'report_json' => 'array',
        'cost_usd' => 'decimal:6',
    ];
}
