<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceExitPolicy extends BaseModel
{
    protected $casts = [
        'default_stop_loss_pct' => 'decimal:4',
        'default_take_profit_pct' => 'decimal:4',
        'default_time_stop_minutes' => 'integer',
        'trailing_stop_enabled' => 'boolean',
        'trailing_stop_pct' => 'decimal:4',
        'partial_take_profit_enabled' => 'boolean',
        'partial_take_profit_levels' => 'array',
        'partial_take_profit_sizes' => 'array',
        'break_even_after_partial' => 'boolean',
        'emergency_exit_on_breaker' => 'boolean',
        'force_exit_on_pause' => 'boolean',
        'force_exit_on_max_daily_loss' => 'boolean',
        'minimum_position_value_for_partial' => 'decimal:8',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
