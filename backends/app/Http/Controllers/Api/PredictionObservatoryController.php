<?php

namespace App\Http\Controllers\Api;

use App\Domain\Prediction\Data\PredictionRequest;
use App\Domain\Prediction\PredictionObservatory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class PredictionObservatoryController extends Controller
{
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
            'candles.*.timestamp' => 'nullable|numeric',
        ]);

        return response()->json($observatory->observe(new PredictionRequest(
            symbol: strtoupper($data['symbol']),
            marketType: $data['market_type'] ?? 'crypto',
            timeframe: $data['timeframe'],
            horizonSeconds: (int) $data['horizon_seconds'],
            currentQuote: isset($data['current_quote']) ? (float) $data['current_quote'] : null,
            candles: $data['candles'],
        )));
    }
}
