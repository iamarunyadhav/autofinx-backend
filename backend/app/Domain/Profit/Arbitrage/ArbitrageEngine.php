<?php

namespace App\Domain\Profit\Arbitrage;

use App\Models\ArbitrageOpportunity;
use Illuminate\Support\Str;

class ArbitrageEngine
{
    public function findOpportunities(array $tickers, float $thresholdPct = 0.2): array
    {
        $found = [];
        foreach ($tickers as $symbol => $venuePrices) {
            $venues = array_keys($venuePrices);
            for ($i = 0; $i < count($venues); $i++) {
                for ($j = $i + 1; $j < count($venues); $j++) {
                    $a = $venues[$i];
                    $b = $venues[$j];
                    $spread = (($venuePrices[$b] - $venuePrices[$a]) / max(0.0001, $venuePrices[$a])) * 100;
                    if ($spread >= $thresholdPct) {
                        $found[] = [
                            'symbol' => $symbol,
                            'buy' => $a,
                            'sell' => $b,
                            'spread' => round($spread, 4),
                        ];
                    }
                }
            }
        }
        return $found;
    }

    public function record(string $workspaceId, array $opportunity): ArbitrageOpportunity
    {
        return ArbitrageOpportunity::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'symbol' => $opportunity['symbol'],
            'buy_venue' => $opportunity['buy'],
            'sell_venue' => $opportunity['sell'],
            'spread_pct' => $opportunity['spread'],
            'meta' => $opportunity,
            'status' => 'simulated',
        ]);
    }
}
