<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickTradeResearchSample extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['price' => 'decimal:10', 'quote_time_ms' => 'integer', 'recorded_at' => 'datetime'];
}
