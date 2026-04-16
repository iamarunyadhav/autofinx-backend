<?php

namespace App\Domain\Portfolio;

use App\Models\PortfolioIntelligenceRun;
use App\Models\OptimizerRun;
use App\Models\Symbol;

class PreTradeSizer
{
    public function size(string $workspaceId, string $symbolId, float $equity, float $price, float $maxTradePct = 2.0): float
    {
        $symbol = strtoupper(trim((string) $symbolId));
        $portfolioRun = PortfolioIntelligenceRun::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('started_at')
            ->first();

        if ($portfolioRun) {
            $allocations = (array) data_get($portfolioRun, 'allocation_json.allocations', []);
            $allocation = (array) data_get($allocations, $symbol, []);
            $notional = (float) data_get($allocation, 'target_notional', 0);
            if ($notional > 0) {
                $cap = $equity * ($maxTradePct / 100);
                $use = min($notional, $cap);

                return max(0.0001, $use / max(0.0001, $price));
            }
        }

        $symbolModel = Symbol::query()->where('symbol', $symbol)->first();
        $weightKey = $symbolModel?->id ?? $symbol;

        $latest = OptimizerRun::where('workspace_id', $workspaceId)->orderByDesc('created_at')->first();
        $weights = $latest?->outputs_json['weights'] ?? [];
        $weight = $weights[$weightKey] ?? $weights[$symbol] ?? (1 / max(1, count($weights) ?: 1));
        $notional = $equity * $weight;
        $cap = $equity * ($maxTradePct / 100);
        $use = min($notional, $cap);
        return max(0.0001, $use / max(0.0001, $price));
    }
}
