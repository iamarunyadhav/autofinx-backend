<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quick Trade ML shadow learning (Phase 1) — shadow only, never read by execution. */
class MlFeatureRow extends Model
{
    protected $table = 'ml_feature_rows';

    protected $guarded = [];

    protected $casts = ['anchor_time_ms' => 'integer', 'anchor_close' => 'float', 'features' => 'array'];
}
