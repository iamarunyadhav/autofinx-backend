<?php

namespace App\Models;

/** Quick Trade ML shadow model registry row — every training attempt, never overwritten. */
class MlModel extends BaseModel
{
    protected $table = 'ml_models';

    protected $casts = [
        'horizon_minutes' => 'integer', 'training_window_from_ms' => 'integer', 'training_window_to_ms' => 'integer',
        'total_samples' => 'integer', 'train_samples' => 'integer', 'holdout_samples' => 'integer',
        'metrics' => 'array', 'comparison' => 'array', 'artifact_checksums' => 'array',
        'trained_at' => 'datetime', 'promoted_at' => 'datetime', 'retired_at' => 'datetime',
    ];
}
