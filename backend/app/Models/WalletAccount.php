<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WalletAccount extends BaseModel
{
    protected $casts = [
        'metadata' => 'array',
        'balance' => 'decimal:8',
        'locked_balance' => 'decimal:8',
        'available' => 'decimal:8',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class, 'account_id');
    }
}
