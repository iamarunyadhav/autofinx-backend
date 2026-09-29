<?php

namespace App\Models;

class PredictionFreshDataset extends BaseModel
{
    protected $casts = [
        'symbols' => 'array',
        'timeframes' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
    ];

    public function candles()
    {
        return $this->hasMany(PredictionFreshDatasetCandle::class, 'dataset_id');
    }
}
