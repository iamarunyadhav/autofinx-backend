<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quick Trade ML shadow learning (Phase 1) — shadow only, never read by execution. */
class MlBackfillCheckpoint extends Model
{
    protected $table = 'ml_backfill_checkpoints';

    protected $guarded = [];

    protected $casts = ['range_from_ms' => 'integer', 'range_to_ms' => 'integer', 'cursor_ms' => 'integer', 'pages' => 'integer', 'rows_written' => 'integer', 'rows_rejected' => 'integer'];
}
