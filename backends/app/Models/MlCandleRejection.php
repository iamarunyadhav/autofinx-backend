<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quick Trade ML shadow learning (Phase 1) — shadow only, never read by execution. */
class MlCandleRejection extends Model
{
    protected $table = 'ml_candle_rejections';

    protected $guarded = [];

    protected $casts = ['open_time_ms' => 'integer', 'payload' => 'array', 'occurrences' => 'integer', 'resolved' => 'boolean'];
}
