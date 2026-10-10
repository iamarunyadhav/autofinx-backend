<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Quick Trade "Manual SL/TP" position (no expiry, exchange-protected).
 * See docs/quick-trade-manual/DESIGN.md. Deliberately NOT a QuickTrade row.
 */
class QuickTradeManualPosition extends BaseModel
{
    public const MODE = 'manual_sl_tp';

    public const ENTRY_PENDING = 'ENTRY_PENDING';
    public const ENTRY_FILLED = 'ENTRY_FILLED';
    public const PARTIALLY_FILLED = 'PARTIALLY_FILLED';
    public const ENTRY_FAILED = 'ENTRY_FAILED';
    public const PROTECTION_PENDING = 'PROTECTION_PENDING';
    public const PROTECTION_FAILED = 'PROTECTION_FAILED';
    public const EMERGENCY_EXIT = 'EMERGENCY_EXIT';
    public const OPEN_PROTECTED = 'OPEN_PROTECTED';
    public const CLOSING = 'CLOSING';
    public const RECONCILIATION = 'RECONCILIATION';
    public const CLOSED = 'CLOSED';
    public const CLOSE_FAILED = 'CLOSE_FAILED';
    public const EXCHANGE_STATE_UNKNOWN = 'EXCHANGE_STATE_UNKNOWN';
    public const LIQUIDATED = 'LIQUIDATED';

    /** No exchange position can remain and no money is reserved. */
    public const TERMINAL = [self::CLOSED, self::LIQUIDATED, self::ENTRY_FAILED];

    /** protection_status values (distinct from the lifecycle `status`). */
    public const PS_NONE = 'NONE';
    public const PS_PENDING = 'PENDING';
    public const PS_PROTECTED = 'PROTECTED';
    public const PS_FAILED = 'FAILED';
    public const PS_LOST = 'LOST';
    public const PS_UNKNOWN = 'UNKNOWN';

    protected $table = 'quick_trade_manual_positions';

    protected $casts = [
        'stake_amount' => 'decimal:8',
        'leverage' => 'decimal:2',
        'requested_quantity' => 'decimal:10',
        'executed_quantity' => 'decimal:10',
        'entry_reference_price' => 'decimal:10',
        'entry_price' => 'decimal:10',
        'entry_slippage_pct' => 'decimal:6',
        'estimated_liquidation_price' => 'decimal:10',
        'exchange_liquidation_price' => 'decimal:10',
        'sl_pct' => 'decimal:4',
        'sl_trigger_price' => 'decimal:10',
        'tp_pct' => 'decimal:4',
        'tp_trigger_price' => 'decimal:10',
        'exit_price' => 'decimal:10',
        'entry_fee' => 'decimal:8',
        'exit_fee' => 'decimal:8',
        'funding_fee' => 'decimal:8',
        'gross_pnl' => 'decimal:8',
        'net_pnl' => 'decimal:8',
        'critical_alert' => 'boolean',
        'emergency_close_confirmed' => 'boolean',
        'last_exchange_state' => 'array',
        'close_attempts' => 'integer',
        'reconcile_failures' => 'integer',
        'sl_verified_at' => 'datetime',
        'tp_verified_at' => 'datetime',
        'opened_at' => 'datetime',
        'protected_at' => 'datetime',
        'closing_at' => 'datetime',
        'closed_at' => 'datetime',
        'wallet_settled_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(QuickTradeManualEvent::class, 'position_id')->orderBy('seq');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function isLong(): bool
    {
        return $this->direction === 'long';
    }

    /** Side of the order that closes this position. */
    public function closeSide(): string
    {
        return $this->isLong() ? 'SELL' : 'BUY';
    }

    /** Hedge-mode positionSide for this position (null in one-way mode). */
    public function positionSide(): ?string
    {
        return $this->position_mode === 'hedge' ? ($this->isLong() ? 'LONG' : 'SHORT') : null;
    }
}
