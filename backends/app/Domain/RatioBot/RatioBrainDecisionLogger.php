<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBrainDecisionLog;

/**
 * Concrete backing for the UI's "Why" panel and shadow-mode comparison --
 * every number the frontend shows must trace to a real field on the row
 * this writes, never a recomputed-on-render guess.
 */
class RatioBrainDecisionLogger
{
    public function log(RatioBotConfig $config, array $result): RatioBrainDecisionLog
    {
        $breakdown = (array) ($result['component_breakdown'] ?? []);

        return RatioBrainDecisionLog::create([
            'ratio_bot_config_id' => $config->id,
            'occurred_at' => now(),
            'market_price' => $result['market_price'] ?? null,
            'position_state' => $result['position_state'] ?? null,
            'action' => $result['action'],
            'reason' => $result['reason'] ?? null,
            'shadow_mode' => (bool) ($result['shadow_mode'] ?? true),
            'trend_score_breakdown' => $breakdown['trend_score'] ?? null,
            'portfolio_guard_result' => $breakdown['portfolio_guard'] ?? null,
            'observation_window_minutes' => data_get($breakdown, 'observation_window.minutes'),
            'sell_candidates' => $breakdown['sell_candidates'] ?? null,
            'emergency_assessment' => $breakdown['emergency'] ?? null,
            'overall_cycle_profit_at_decision_time' => data_get($breakdown, 'portfolio_guard.realized_cycle_profit'),
            'executed' => false,
        ]);
    }

    public function markExecuted(RatioBrainDecisionLog $log): void
    {
        $log->update(['executed' => true]);
    }
}
