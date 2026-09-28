<?php

namespace App\Models;

class PredictionMarketSnapshot extends BaseModel
{
    protected $table = 'prediction_market_snapshots';

    public $incrementing = true;

    protected $keyType = 'int';

    public function uniqueIds(): array
    {
        return [];
    }

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'quality_json' => 'array',
        'captured_at' => 'datetime',
    ];
}
