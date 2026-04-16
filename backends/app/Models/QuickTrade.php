<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickTrade extends BaseModel
{
    protected $casts = [
        'stake_amount' => 'decimal:8',
        'quantity' => 'decimal:8',
        'payout_ratio' => 'decimal:4',
        'entry_price' => 'decimal:8',
        'exit_price' => 'decimal:8',
        'profit_loss' => 'decimal:8',
        'payout_amount' => 'decimal:8',
        'fees_total' => 'decimal:8',
        'confidence' => 'integer',
        'signal_payload' => 'array',
        'indicator_snapshot' => 'array',
        'entry_at' => 'datetime',
        'expires_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function robotSession(): BelongsTo
    {
        return $this->belongsTo(RobotSession::class);
    }

    public function walletAccount(): BelongsTo
    {
        return $this->belongsTo(WalletAccount::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
