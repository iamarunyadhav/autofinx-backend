<?php

namespace App\Models;

/**
 * One row per individual walk-forward prediction (see
 * `ForecastHorizonBacktestService::walkForward()` and the
 * `2026_09_19_010000_create_forecast_backtest_predictions` migration for the
 * full rationale). Additive companion to the bucket-level-aggregate
 * `RatioBotForecastBacktest` table -- never read by any of that table's
 * existing consumers (ForecastModelPromotionGate/ForecastMetricsService/
 * ForecastModelComparisonService/ForecastProductionReadinessReport), only by
 * `ForecastCalibrationQualityService`'s new exact-Brier-score path.
 */
class ForecastBacktestPrediction extends BaseModel
{
    protected $table = 'forecast_backtest_predictions';

    protected $casts = [
        'bot_type'          => 'string',
        'bot_instance_id'   => 'string',
        'horizon_seconds'   => 'integer',
        'evaluation_period' => 'string',
        'confidence_pct'    => 'decimal:2',
        'predicted_hit'     => 'boolean',
        'candle_ts'         => 'datetime',
        'backtest_run_id'   => 'string',
    ];
}
