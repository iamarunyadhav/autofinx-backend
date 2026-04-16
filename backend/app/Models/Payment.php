<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends BaseModel
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PENDING_PROVIDER = 'pending_provider';
    public const STATUS_PENDING_WEBHOOK = 'pending_webhook';
    public const STATUS_SUBMITTED_TO_PROVIDER = 'submitted_to_provider';
    public const STATUS_PROVIDER_PENDING = 'provider_pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_EXPIRED = 'expired';

    protected $casts = [
        'metadata' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    public function scopeIdempotent($query, string $key)
    {
        return $query->where('idempotency_key', $key);
    }
}
