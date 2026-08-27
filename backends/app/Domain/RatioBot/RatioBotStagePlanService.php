<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;

class RatioBotStagePlanService
{
    /**
     * @return array{buy_allocations: array<int, float>, buy_drops: array<int, float>, sell_allocations: array<int, float>, sell_rises: array<int, float>, reserve_pct: float}
     */
    public function plan(RatioBotConfig $config): array
    {
        $buyAllocations = $this->normalPercentArray($config->stage_buy_allocations, [50, 25, 25]);
        $buyDrops = $this->normalPercentArray($config->stage_buy_drops, [10, 5, 5]);
        $sellAllocations = $this->normalPercentArray($config->stage_sell_allocations, array_reverse($buyAllocations));
        $sellRises = $this->normalPercentArray($config->stage_sell_rises, [5, 5, 10]);

        return [
            'buy_allocations' => $buyAllocations,
            'buy_drops' => $buyDrops,
            'sell_allocations' => $sellAllocations,
            'sell_rises' => $sellRises,
            'reserve_pct' => max(0.0, min(100.0, $this->percentValue($config->strategy_reserve_pct ?? 0))),
        ];
    }

    public function enabled(RatioBotConfig $config): bool
    {
        return $config->stagePlanEnabled();
    }

    public function deployableQuote(RatioBotConfig $config): float
    {
        $base = (float) ($config->base_quote_amount ?? $config->quote_asset_budget ?? 0);
        $reservePct = $this->plan($config)['reserve_pct'];

        return max(0.0, $base * (1 - ($reservePct / 100)));
    }

    /**
     * @param mixed $value
     * @param array<int, int|float> $fallback
     * @return array<int, float>
     */
    private function normalPercentArray(mixed $value, array $fallback): array
    {
        $raw = is_array($value) ? $value : $fallback;
        $items = [];

        foreach ($raw as $item) {
            $pct = $this->percentValue($item);
            if ($pct > 0) {
                $items[] = round($pct, 4);
            }
        }

        return $items ?: array_map(fn ($item) => (float) $item, $fallback);
    }

    private function percentValue(mixed $value): float
    {
        $number = (float) $value;

        return $number > 0 && $number <= 1 ? $number * 100 : $number;
    }
}
