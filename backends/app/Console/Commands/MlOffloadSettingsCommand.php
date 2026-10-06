<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Prints the settings that decide a training verdict (costs, promotion gates,
 * window) as KEY=VALUE lines, so a training run on another machine uses the
 * same numbers as this server. Read-only; prints no secrets.
 */
final class MlOffloadSettingsCommand extends Command
{
    protected $signature = 'ml:offload:settings';

    protected $description = 'Print the ML/cost settings a remote training run must copy (KEY=VALUE lines).';

    public function handle(): int
    {
        $bool = static fn ($v): string => $v ? 'true' : 'false';
        $pairs = [
            'QUICK_TRADE_FEE_MODEL' => config('quick_trade.fee_model'),
            'QUICK_TRADE_MAKER_FEE_RATE' => config('quick_trade.maker_fee_rate'),
            'QUICK_TRADE_ALLOW_MAKER_ENTRY_IN_LIVE' => $bool(config('quick_trade.allow_maker_entry_in_live')),
            'ROBOT_TRADE_LIVE_SLIPPAGE_BUFFER_PCT' => config('robot_trade.live_slippage_buffer_pct'),
            'ROBOT_TRADE_LIVE_MIN_EDGE_COST_MULTIPLIER' => config('robot_trade.live_min_edge_cost_multiplier'),
            'AUTOFINX_ML_SYMBOLS' => implode(',', (array) config('ml.symbols')),
            'AUTOFINX_ML_HORIZONS_MINUTES' => implode(',', (array) config('ml.horizons_minutes')),
            'AUTOFINX_ML_CANDIDATE_TYPES' => implode(',', (array) config('ml.training.candidate_types')),
            'AUTOFINX_ML_MAX_TRAINING_ROWS' => config('ml.training.max_training_rows'),
            'AUTOFINX_ML_TRAINING_WINDOW_MONTHS' => config('ml.training.window_months'),
            'AUTOFINX_ML_RECENCY_HALF_LIFE_DAYS' => config('ml.training.recency_half_life_days'),
            'AUTOFINX_ML_MIN_HOLDOUT_ROWS' => config('ml.promotion.min_holdout_rows'),
            'AUTOFINX_ML_MIN_MAE_IMPROVEMENT_VS_BASELINE' => config('ml.promotion.min_mae_improvement_vs_baseline'),
            'AUTOFINX_ML_MIN_MAE_IMPROVEMENT_VS_CHAMPION' => config('ml.promotion.min_mae_improvement_vs_champion'),
            'AUTOFINX_ML_MIN_POSITIVE_FOLD_SHARE' => config('ml.promotion.classifier.min_positive_fold_share'),
            'AUTOFINX_ML_MIN_NET_IMPROVEMENT_VS_CHAMPION_PCT' => config('ml.promotion.classifier.min_net_improvement_vs_champion_pct'),
            'AUTOFINX_ML_MIN_AVG_NET_PER_TRADE_PCT' => config('ml.promotion.classifier.min_avg_net_per_trade_pct'),
            'AUTOFINX_ML_MIN_T_STAT' => config('ml.promotion.classifier.min_t_stat'),
            'AUTOFINX_ML_CLASSIFIER_PROBABILITY_FLOOR' => config('ml.classifier.probability_floor'),
        ];
        foreach ($pairs as $key => $value) {
            $this->line($key.'='.$value);
        }

        return self::SUCCESS;
    }
}
