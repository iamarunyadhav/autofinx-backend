<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FutureTradeConfigVersion extends BaseModel
{
    protected $table = 'future_trade_config_versions';

    protected $casts = [
        'settings_json' => 'array',
        'summary_json' => 'array',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(FutureTradeBotConfig::class, 'future_trade_bot_config_id');
    }
}
