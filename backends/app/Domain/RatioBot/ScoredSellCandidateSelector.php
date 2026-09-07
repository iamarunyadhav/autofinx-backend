<?php

namespace App\Domain\RatioBot;

use App\Models\RatioBotConfig;
use App\Models\RatioBotLot;
use Illuminate\Support\Carbon;

/**
 * Re-orders (never re-filters) the already-safe eligible-lot set
 * RatioBotLotService::profitableFifoSellPlan() determined -- the profit
 * floor gate stays exactly as-is upstream (unmodified); this only decides
 * which of the already-eligible lots is objectively the best one when a
 * partial sell can't take all of them ("do not sell a lot merely because it
 * is the oldest").
 *
 * Verified before writing this: profitableFifoSellPlan() returns an
 * aggregate plan (qty/lot_ids/min-max buy price), not per-lot profit%/age/
 * capital-release fields -- those are derived here directly from the real
 * RatioBotLot columns (buy_price, remaining_qty, bought_at).
 */
class ScoredSellCandidateSelector
{
    /**
     * @param  array<int, string>  $eligibleLotIds  from profitableFifoSellPlan()'s `lot_ids`
     * @return array<int, array{lot_id: string, buy_stage_index: int, net_profit_pct: float, capital_release: float, age_hours: float, is_last_open_lot: bool, score: float}>
     */
    public function rank(RatioBotConfig $config, array $eligibleLotIds, float $currentPrice): array
    {
        if ($eligibleLotIds === []) {
            return [];
        }

        $weights = $config->sellSelectorWeights();
        $lots = RatioBotLot::query()->whereIn('id', $eligibleLotIds)->get()->keyBy('id');
        $totalOpenCount = app(RatioBotLotService::class)->openLots($config)->count();
        $now = Carbon::now();

        $rows = [];
        foreach ($eligibleLotIds as $lotId) {
            $lot = $lots->get($lotId);
            if (! $lot) {
                continue;
            }

            $buyPrice = (float) $lot->buy_price;
            $remainingQty = (float) $lot->remaining_qty;

            $rows[] = [
                'lot_id' => (string) $lot->id,
                'buy_stage_index' => (int) $lot->buy_stage_index,
                'net_profit_pct' => $buyPrice > 0 ? round((($currentPrice - $buyPrice) / $buyPrice) * 100, 4) : 0.0,
                'capital_release' => round($remainingQty * $currentPrice, 8),
                'age_hours' => $lot->bought_at ? round($lot->bought_at->diffInMinutes($now) / 60, 2) : 0.0,
                'is_last_open_lot' => $totalOpenCount === 1,
            ];
        }

        if ($rows === []) {
            return [];
        }

        // Normalize each raw component into a comparable 0-100 scale before
        // weighting -- profit% and capital-release-in-quote-currency live on
        // wildly different scales, and would otherwise let whichever has the
        // larger raw numbers dominate the score regardless of its weight.
        $profitValues = array_column($rows, 'net_profit_pct');
        $capitalValues = array_column($rows, 'capital_release');
        $ageValues = array_column($rows, 'age_hours');
        $maxProfitPct = max(0.01, max(array_map(fn ($v) => max(0.0, $v), $profitValues)));
        $maxCapitalRelease = max(0.01, max($capitalValues));
        $maxAgeHours = max(0.01, max($ageValues));

        foreach ($rows as &$row) {
            $profitComponent = max(0.0, $row['net_profit_pct']) / $maxProfitPct * 100;
            $capitalComponent = $row['capital_release'] / $maxCapitalRelease * 100;
            $ageComponent = $row['age_hours'] / $maxAgeHours * 100;
            // Closing the bot's last remaining open lot brings it fully flat
            // -- a real, distinct benefit (frees the whole position, resets
            // the cycle) beyond the numeric profit alone.
            $cycleEffectComponent = $row['is_last_open_lot'] ? 100.0 : 0.0;

            $row['score'] = round(
                $profitComponent * $weights['profit']
                + $capitalComponent * $weights['capital_release']
                + $ageComponent * $weights['age']
                + $cycleEffectComponent * $weights['cycle_effect'],
                4,
            );
        }
        unset($row);

        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $rows;
    }
}
