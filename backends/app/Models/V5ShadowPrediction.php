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
        'evaluated_at' => 'datetime', 'outcome_observed_at' => 'datetime',
        'probabilities' => 'array', 'evidence' => 'array',
        'probability_calibrated' => 'boolean', 'correct' => 'boolean',
        'reference_price' => 'float', 'outcome_price' => 'float',
        'actual_move' => 'float', 'expected_move' => 'float',
    ];
}
