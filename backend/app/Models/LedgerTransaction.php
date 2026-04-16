<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerTransaction extends BaseModel
{
    protected $casts = [
        'meta' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function walletAccount(): BelongsTo
    {
        return $this->belongsTo(WalletAccount::class);
    }

    public function scopeIdempotent($query, string $key)
    {
        return $query->where('idempotency_key', $key);
    }
}
