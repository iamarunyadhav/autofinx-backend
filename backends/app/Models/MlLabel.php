<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quick Trade ML shadow learning (Phase 1) — shadow only, never read by execution. */
class MlLabel extends Model
{
    protected $table = 'ml_labels';

    protected $guarded = [];

    protected $casts = ['horizon_minutes' => 'integer', 'anchor_time_ms' => 'integer', 'target_time_ms' => 'integer', 'anchor_close' => 'float', 'target_close' => 'float', 'future_return' => 'float', 'matured_at' => 'datetime'];
}
