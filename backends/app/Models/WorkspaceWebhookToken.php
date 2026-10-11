<?php

namespace App\Models;

class WorkspaceWebhookToken extends BaseModel
{
    public const SCOPE_TRADINGVIEW = 'tradingview';

    protected $casts = [
        'rotated_at' => 'datetime',
    ];
}
