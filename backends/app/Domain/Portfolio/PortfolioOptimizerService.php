<?php

namespace App\Domain\Portfolio;

use App\Models\OptimizerRun;
use App\Models\Position;
use App\Models\Workspace;
use Illuminate\Support\Str;

class PortfolioOptimizerService
{
    public function run(string $workspaceId, string $strategy = 'fixed'): OptimizerRun
    {
        $positions = Position::where('workspace_id', $workspaceId)->get();
        $weights = [];
        $count = max(1, $positions->count());

        if ($strategy === 'fixed' || $positions->count() === 0) {
            $equal = 1 / $count;
            foreach ($positions as $pos) {
                $weights[$pos->symbol_id] = $equal;
            }
        } elseif ($strategy === 'risk_parity') {
            $weights = $this->riskParity($positions);
        } elseif ($strategy === 'kelly_bounded') {
            $weights = $this->kellyBounded($positions);
        } elseif ($strategy === 'dd_constrained') {
            $weights = $this->drawdownConstrained($positions);
        }

        return OptimizerRun::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'strategy' => $strategy,
            'inputs_json' => ['positions' => $positions->map->only(['symbol_id','quantity'])->all()],
            'outputs_json' => ['weights' => $weights],
        ]);
    }

    private function riskParity($positions): array
    {
        $vols = [];
        foreach ($positions as $pos) {
            $vols[$pos->symbol_id] = $this->volatility($pos->symbol_id) ?: 1;
        }
        $invVol = [];
        foreach ($vols as $sym => $v) {
            $invVol[$sym] = $v > 0 ? 1 / $v : 0;
        }
        $sum = array_sum($invVol) ?: 1;
        $weights = [];
        foreach ($invVol as $sym => $iv) {
            $weights[$sym] = $iv / $sum;
        }
        return $weights;
    }

    private function kellyBounded($positions): array
    {
        $count = max(1, $positions->count());
        $base = 1 / $count;
        $weights = [];
        foreach ($positions as $pos) {
            // Simple bounded Kelly proxy: cap at 10% per symbol
            $weights[$pos->symbol_id] = min(0.1, $base);
        }
        $sum = array_sum($weights) ?: 1;
        foreach ($weights as $sym => $w) {
            $weights[$sym] = $w / $sum;
        }
        return $weights;
    }

    private function drawdownConstrained($positions): array
    {
        $weights = [];
        $count = max(1, $positions->count());
        $equal = 1 / $count;
        foreach ($positions as $pos) {
            $weights[$pos->symbol_id] = $equal * 0.8; // reduce exposure 20% globally
        }
        $sum = array_sum($weights) ?: 1;
        foreach ($weights as $sym => $w) {
            $weights[$sym] = $w / $sum;
        }
        return $weights;
    }

    private function volatility(string $symbolId): ?float
    {
        $candles = \App\Models\MarketCandle::where('symbol_id', $symbolId)
            ->orderByDesc('opens_at')
            ->limit(50)
            ->get();
        if ($candles->count() < 5) {
            return null;
        }
        $closes = $candles->pluck('close')->toArray();
        $mean = array_sum($closes) / count($closes);
        $var = 0.0;
        foreach ($closes as $c) {
            $var += pow($c - $mean, 2);
        }
        $var = $var / count($closes);
        return sqrt($var);
    }
}
