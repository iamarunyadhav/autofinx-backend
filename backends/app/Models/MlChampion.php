<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quick Trade ML shadow learning (Phase 1) — shadow only, never read by execution. */
class MlChampion extends Model
{
    protected $table = 'ml_champions';

    protected $guarded = [];

    protected $casts = ['horizon_minutes' => 'integer'];
}
