<?php

namespace App\Models;

class PredictionFreshModelRegistry extends BaseModel
{
    protected $table = 'prediction_fresh_model_registry';

    protected $casts = ['execution' => 'boolean', 'influence' => 'boolean'];

    public function candidate()
    {
        return $this->belongsTo(PredictionFreshModelCandidate::class, 'candidate_id');
    }
}
