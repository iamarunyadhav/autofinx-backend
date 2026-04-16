<?php

namespace App\Domain\Forecast;

use App\Models\MarketCandle;

class ForecastService
{
    /**
     * Simple EMA-based forecast stub.
     */
    public function predictNext(string $symbol, int $horizonMinutes = 5): array
    {
        $candles = MarketCandle::whereHas('symbol', function ($q) use ($symbol) {
            $q->where('symbol', $symbol);
        })
            ->orderByDesc('opens_at')
            ->limit(30)
            ->get();

        if ($candles->count() < 3) {
            return ['price' => null, 'confidence' => 0.1];
        }

        $closes = $candles->pluck('close')->reverse()->values()->all();
        $ema = $this->ema($closes, min(10, count($closes)));
        $last = $closes[count($closes) - 1];
        $trend = $ema - $last;
        $forecast = $last + ($trend * ($horizonMinutes / 5));

        return [
            'price' => round($forecast, 4),
            'confidence' => 0.4,
        ];
    }

    private function ema(array $values, int $period): float
    {
        $k = 2 / ($period + 1);
        $ema = $values[0];
        for ($i = 1; $i < count($values); $i++) {
            $ema = ($values[$i] * $k) + ($ema * (1 - $k));
        }
        return $ema;
    }
}
