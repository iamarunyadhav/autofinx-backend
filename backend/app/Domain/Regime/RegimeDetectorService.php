<?php

namespace App\Domain\Regime;

use App\Models\MarketCandle;
use App\Models\MarketRegime;
use App\Models\Symbol;
use Illuminate\Support\Str;

class RegimeDetectorService
{
    public function detect(string $symbolCode, ?string $workspaceId = null): ?MarketRegime
    {
        $symbol = Symbol::where('symbol', $symbolCode)->first();
        if (!$symbol) return null;

        $candles = MarketCandle::where('symbol_id', $symbol->id)
            ->orderByDesc('opens_at')
            ->limit(50)
            ->get();
        if ($candles->count() < 10) return null;

        $closes = $candles->pluck('close')->reverse()->values()->all();
        $returns = [];
        for ($i = 1; $i < count($closes); $i++) {
            $returns[] = ($closes[$i] - $closes[$i-1]) / max(0.0001, $closes[$i-1]);
        }
        $vol = $this->stddev($returns);
        $range = (max($closes) - min($closes)) / max(0.0001, min($closes));
        $trend = $closes[count($closes)-1] - $closes[0];

        $regime = 'RANGING';
        if ($vol > 0.02) $regime = 'HIGH_VOLATILITY';
        elseif ($vol < 0.005) $regime = 'LOW_VOLATILITY';
        if ($trend > 0 && $range > 0.01) $regime = 'TRENDING';

        return MarketRegime::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'symbol_id' => $symbol->id,
            'regime' => $regime,
            'meta_json' => ['vol' => $vol, 'range' => $range, 'trend' => $trend],
            'detected_at' => now(),
        ]);
    }

    private function stddev(array $values): float
    {
        if (count($values) === 0) return 0;
        $mean = array_sum($values) / count($values);
        $var = 0.0;
        foreach ($values as $v) { $var += pow($v - $mean, 2); }
        return sqrt($var / count($values));
    }
}
