<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quick Trade ML shadow learning (Phase 1) — shadow only, never read by execution. */
class MlTradeFeedbackTier extends Model
{
    protected $table = 'ml_trade_feedback_tiers';

    protected $guarded = [];

    protected $casts = ['reasons' => 'array', 'classified_at' => 'datetime'];
}
