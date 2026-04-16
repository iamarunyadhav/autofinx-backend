<?php

namespace App\Models;

class AiTradingConfig extends BaseModel
{
    protected $casts = [
        'enabled' => 'boolean',
        'enabled_symbols' => 'array',
        'allowed_symbols' => 'array',
        'blocked_symbols' => 'array',
        'max_trade_size_pct' => 'decimal:4',
        'min_confidence' => 'decimal:4',
        'cooldown_minutes' => 'integer',
        'max_pending_decisions_per_symbol' => 'integer',
        'max_decisions_per_hour_per_symbol' => 'integer',
        'decision_interval_minutes' => 'integer',
        'max_decisions_per_hour' => 'integer',
        'cooldown_minutes_after_trade' => 'integer',
        'cooldown_minutes_after_loss' => 'integer',
        'pause_until' => 'datetime',
        'auto_pause_on_daily_loss' => 'boolean',
        'auto_pause_on_drawdown' => 'boolean',
        'auto_pause_on_order_failure_streak' => 'integer',
        'auto_pause_on_slippage_spike' => 'boolean',
        'auto_pause_on_reconciliation_mismatch' => 'boolean',
        'manual_resume_required' => 'boolean',
        'use_external_context' => 'boolean',
        'require_stop_loss' => 'boolean',
        'require_take_profit' => 'boolean',
        'require_time_stop' => 'boolean',
        'auto_trade_enabled' => 'boolean',
    ];

    public function effectiveEnabledSymbols(): array
    {
        $symbols = $this->normalizeSymbols($this->enabled_symbols);
        if ($symbols !== []) {
            return $symbols;
        }

        return $this->normalizeSymbols($this->allowed_symbols);
    }

    private function normalizeSymbols(array|string|null $symbols): array
    {
        $list = is_array($symbols) ? $symbols : explode(',', (string) $symbols);

        return array_values(array_filter(array_map(
            fn ($symbol) => strtoupper(trim((string) $symbol)),
            $list
        )));
    }
}
