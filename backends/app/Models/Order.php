<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends BaseModel
{
    protected $casts = [
        'meta' => 'array',
        'raw_json' => 'array',
        'qty' => 'decimal:8',
        'limit_price' => 'decimal:8',
        'requested_qty' => 'decimal:8',
        'filled_qty' => 'decimal:8',
        'avg_fill_price' => 'decimal:8',
        'fee_amount' => 'decimal:8',
        'placed_ts' => 'datetime',
        'filled_ts' => 'datetime',
        'provider_synced_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'uncertain_at' => 'datetime',
        'sync_error_count' => 'integer',
        'slippage_pct' => 'decimal:4',
        'spread_pct' => 'decimal:4',
        'expected_profit_pct' => 'decimal:4',
    ];

    public const STATUS_PENDING_PROVIDER = 'pending_provider';
    public const STATUS_FILLED = 'filled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NEW = 'NEW';
    public const STATUS_PARTIAL = 'PARTIAL';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_UNCERTAIN = 'UNCERTAIN';

    public const RECONCILIATION_PENDING = 'pending';
    public const RECONCILIATION_ALIGNED = 'aligned';
    public const RECONCILIATION_MISMATCH = 'mismatch';
    public const RECONCILIATION_UNCERTAIN = 'uncertain';

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(StrategyInstance::class, 'instance_id');
    }

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class, 'bot_id');
    }

    public function signal(): BelongsTo
    {
        return $this->belongsTo(TradeSignal::class, 'signal_id');
    }

    public function trades(): HasMany
    {
        return $this->hasMany(Trade::class);
    }

    public function fills(): HasMany
    {
        return $this->hasMany(OrderFill::class);
    }

    public function executionAttempts(): HasMany
    {
        return $this->hasMany(ExecutionAttempt::class);
    }

    public function executionEvents(): HasMany
    {
        return $this->hasMany(LiveExecutionEvent::class);
    }

    public function scopeIdempotent($query, string $key)
    {
        return $query->where('idempotency_key', $key);
    }
}
