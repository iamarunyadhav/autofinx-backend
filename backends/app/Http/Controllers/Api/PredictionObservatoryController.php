<?php

namespace App\Http\Controllers\Api;

use App\Domain\Prediction\Data\PredictionRequest;
use App\Domain\Prediction\PredictionObservatory;
use App\Domain\Prediction\PredictionAccuracyReport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class PredictionObservatoryController extends Controller
{
    public function accuracyReport(Request $request, PredictionAccuracyReport $report)
    {
        $data = $request->validate([
            'rows' => 'nullable|array|max:5000',
            'rows.*.provider' => 'required_with:rows|string|max:64',
            'rows.*.timeframe' => 'required_with:rows|string|max:16',
            'rows.*.direction_correct' => 'required_with:rows|boolean',
            'rows.*.return_pct' => 'nullable|numeric',
        ]);

        return response()->json($report->generate($data['rows'] ?? []));
    }

    public function observe(Request $request, PredictionObservatory $observatory)
    {
        $data = $request->validate([
            'symbol' => 'required|string|max:32',
            'market_type' => 'nullable|string|in:crypto,forex,metals',
            'timeframe' => 'required|string|in:1m,5m,10m,15m,30m,1h,2h,4h,1d',
            'horizon_seconds' => 'required|integer|min:60|max:86400',
            'current_quote' => 'nullable|numeric|min:0',
            'candles' => 'required|array|min:8|max:500',
            'candles.*.open' => 'required|numeric',
            'candles.*.high' => 'required|numeric',
            'candles.*.low' => 'required|numeric',
            'candles.*.close' => 'required|numeric',
            'candles.*.volume' => 'nullable|numeric',
            'candles.*.timestamp' => 'required|numeric',
        ]);

        $intervals = ['1m' => 60, '5m' => 300, '10m' => 600, '15m' => 900, '30m' => 1800,
            '1h' => 3600, '2h' => 7200, '4h' => 14400, '1d' => 86400];
        $interval = $intervals[$data['timeframe']];
        $now = time();
        $closedCandles = array_values(array_filter($data['candles'], static function (array $candle) use ($interval, $now): bool {
            $open = (float) $candle['timestamp'];
            if ($open > 1_000_000_000_000) {
                $open /= 1000;
            }
            return $open + $interval <= $now;
        }));

        if (count($closedCandles) < 8) {
            return response()->json([
                'message' => 'At least 8 fully closed candles are required.',
                'reason' => 'insufficient_closed_candles',
            ], 422);
        }

        return response()->json($observatory->observe(new PredictionRequest(
            symbol: strtoupper($data['symbol']),
            marketType: $data['market_type'] ?? 'crypto',
            timeframe: $data['timeframe'],
            horizonSeconds: (int) $data['horizon_seconds'],
            currentQuote: isset($data['current_quote']) ? (float) $data['current_quote'] : null,
            candles: $closedCandles,
        )));
    }
}
