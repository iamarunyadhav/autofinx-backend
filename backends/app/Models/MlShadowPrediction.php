<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quick Trade ML shadow learning (Phase 1) — shadow only, never read by execution. */
class MlShadowPrediction extends Model
{
    protected $table = 'ml_shadow_predictions';

    protected $guarded = [];

    protected $casts = ['horizon_minutes' => 'integer', 'anchor_time_ms' => 'integer', 'target_time_ms' => 'integer', 'current_price' => 'float', 'predicted_return' => 'float', 'predicted_price' => 'float', 'predicted_low' => 'float', 'predicted_high' => 'float', 'buy_net_pct' => 'float', 'sell_net_pct' => 'float', 'cost_snapshot' => 'array', 'actual_price' => 'float', 'actual_return' => 'float', 'direction_hit' => 'boolean', 'abs_error_pct' => 'float', 'decision_net_pct' => 'float', 'scored_at' => 'datetime'];
}
