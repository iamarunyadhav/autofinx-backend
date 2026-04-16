<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletLedgerEntry extends BaseModel
{
    protected $casts = [
        'amount' => 'decimal:8',
        'meta_json' => 'array',
        'ts' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(WalletAccount::class, 'account_id');
    }
}
