<?php

namespace App\Models;

class PredictionFreshModelCandidate extends BaseModel
{
    protected $casts = ['metrics' => 'array'];

    public function dataset()
    {
        return $this->belongsTo(PredictionFreshDataset::class, 'dataset_id');
    }
}
