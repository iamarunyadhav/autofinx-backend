<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per window (short/medium/long) of a
 * MultiWindowForecastComparisonService::compare() request, linked by
 * `correlation_id`. Observation only -- see that service and
 * MultiWindowForecastAuditService for how rows are written/evaluated.
 * Market-level, not workspace-owned (mirrors QuickTradeWaitShadow):
 * `workspace_id` is recorded for provenance only, never used to scope a
 * uniqueness/ownership rule.
 */
class MultiWindowForecastAudit extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'expiry_seconds' => 'integer',
        'expiry_score' => 'float',
        'expected_move_pct' => 'float',
        'required_move_pct' => 'float',
        'cost_model' => 'array',
        'generated_at' => 'datetime',
        'reference_price' => 'float',
        'target_ms' => 'integer',
        'actual_price' => 'float',
        'direction_correct' => 'boolean',
        'hypothetical_net_usdt' => 'float',
        'evaluated_at' => 'datetime',
    ];
}
