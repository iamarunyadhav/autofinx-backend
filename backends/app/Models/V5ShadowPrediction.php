<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class V5ShadowPrediction extends Model
{
    use HasUuids;

    protected $table = 'v5_shadow_predictions';
    protected $guarded = ['id'];
    protected $casts = [
        'forecast_at' => 'datetime', 'outcome_available_at' => 'datetime',
        'evaluated_at' => 'datetime', 'probabilities' => 'array', 'evidence' => 'array',
        'probability_calibrated' => 'boolean', 'correct' => 'boolean',
    ];
}
