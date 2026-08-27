<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\RatioBotState;

class StrategyHealthMonitor
{
    public const HEALTHY = 'HEALTHY';
    public const BUY_RANGE_STRETCHED = 'BUY_RANGE_STRETCHED';
    public const SELL_RANGE_STRETCHED = 'SELL_RANGE_STRETCHED';
    public const STRATEGY_RANGE_EXHAUSTED_DOWN = 'STRATEGY_RANGE_EXHAUSTED_DOWN';
    public const STRATEGY_RANGE_EXHAUSTED_UP = 'STRATEGY_RANGE_EXHAUSTED_UP';
    public const CAPITAL_LOCKED = 'CAPITAL_LOCKED';
    public const PROFIT_WINDOW_UNAVAILABLE = 'PROFIT_WINDOW_UNAVAILABLE';
    public const REVIEW_RECOMMENDED = 'REVIEW_RECOMMENDED';

    public function __construct(
        private readonly RatioBotStagePlanService $stagePlan,
        private readonly RatioBotLotService $lots,
    ) {
    }

    public function update(RatioBotConfig $config, RatioBotState $state, float $price): array
    {
        if (! $this->stagePlan->enabled($config)) {
            return ['health' => self::HEALTHY, 'range_status' => null, 'review' => false];
        }

        $plan = $this->stagePlan->plan($config);
        $buyStages = count($plan['buy_allocations']);
        $sellStages = count($plan['sell_allocations']);
        $buyFilled = $this->lots->buyStagesFilled($config);
        $openLots = $this->lots->openLots($config);
        $closedLots = $this->lots->sellStagesCompleted($config);

        $health = self::HEALTHY;
        $range = null;
        $review = false;

        if ($buyFilled >= $buyStages && $openLots->isNotEmpty()) {
            $lowestBuy = (float) $openLots->min('buy_price');
            if ($lowestBuy > 0 && $price < $lowestBuy) {
                $health = self::STRATEGY_RANGE_EXHAUSTED_DOWN;
                $range = self::STRATEGY_RANGE_EXHAUSTED_DOWN;
                $review = true;
            }
        }

        if ($closedLots >= $sellStages && $openLots->isEmpty() && (float) ($state->last_sell_price ?? 0) > 0) {
            $lastSell = (float) $state->last_sell_price;
            if ($price > $lastSell) {
                $health = self::STRATEGY_RANGE_EXHAUSTED_UP;
                $range = self::STRATEGY_RANGE_EXHAUSTED_UP;
                $review = true;
            }
        }

        $usedBuy = min(100.0, array_sum(array_slice($plan['buy_allocations'], 0, min($buyFilled, $buyStages))));
        $usedSell = min(100.0, array_sum(array_slice($plan['sell_allocations'], 0, min($closedLots, $sellStages))));

        $state->update([
            'strategy_health_status' => $health,
            'range_status' => $range,
            'observed_low_price' => $state->observed_low_price ? min((float) $state->observed_low_price, $price) : $price,
            'observed_high_price' => $state->observed_high_price ? max((float) $state->observed_high_price, $price) : $price,
            'used_buy_allocation_pct' => $usedBuy,
            'used_sell_allocation_pct' => $usedSell,
        ]);

        if ($review && (! $state->review_alerted_at || $state->review_alerted_at->lt(now()->subHours(6)))) {
            RatioBotEvent::create([
                'ratio_bot_config_id' => $config->id,
                'event_type' => 'review_recommended',
                'detail' => $health,
                'context' => [
                    'symbol' => $config->normalizedSymbol(),
                    'price' => $price,
                    'buy_stages_filled' => $buyFilled,
                    'buy_stages_total' => $buyStages,
                    'sell_stages_completed' => $closedLots,
                    'sell_stages_total' => $sellStages,
                    'current_action' => 'HOLD',
                ],
                'occurred_at' => now(),
            ]);
            $state->update(['review_alerted_at' => now()]);
        }

        return ['health' => $health, 'range_status' => $range, 'review' => $review];
    }
}
