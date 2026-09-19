<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatioBotForecastBacktest extends BaseModel
{
    protected $casts = [
        'bot_type'            => 'string',
        'bot_instance_id'     => 'string',
        'confidence_bucket'   => 'string',
        'evaluation_period'   => 'string',
        'horizon_seconds'     => 'integer',
        'sample_size'         => 'integer',
        'hits'                => 'integer',
        'hit_rate_pct'        => 'decimal:2',
        'avg_confidence_pct'  => 'decimal:2',
        'computed_at'         => 'datetime',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(RatioBotConfig::class, 'ratio_bot_config_id');
    }

    /**
     * Backfills the three generalization columns (bot_type/bot_instance_id/
     * confidence_bucket -- added to support Quick Trade/Robot Trade/Future
     * Trade/Fast2X calibration in a later task) for any row created without
     * them explicitly set. Every real code path
     * (ForecastHorizonBacktestService::runForConfig()/runForBot()) already
     * sets all three explicitly, so this hook is a no-op for them; it only
     * fires for the pre-existing direct ::create() calls scattered across
     * this codebase's older Ratio-Bot-only tests, which only ever supply
     * `ratio_bot_config_id` -- exactly the value bot_instance_id should
     * backfill to per the plan's own stated convention, so those tests keep
     * passing unmodified while bot_instance_id still stays truly NOT NULL
     * at the database level (plan correction #4).
     */
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (blank($model->bot_type)) {
                $model->bot_type = 'ratio_bot';
            }
            if (blank($model->bot_instance_id)) {
                $model->bot_instance_id = $model->ratio_bot_config_id !== null
                    ? (string) $model->ratio_bot_config_id
                    : 'unknown';
            }
            if (blank($model->confidence_bucket)) {
                $model->confidence_bucket = 'all';
            }
            if (blank($model->evaluation_period)) {
                $model->evaluation_period = 'full';
            }
        });
    }
}
