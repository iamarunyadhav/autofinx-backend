<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Historical chart pages only: never writes execution quotes or freshness. */
class QuickTradeCandleHistoryController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'symbol' => ['required', 'regex:/^[A-Z0-9]{2,32}$/'],
            'timeframe' => 'required|in:1m,3m,5m,15m,30m,1h,2h,4h,6h,8h,12h,1d,3d,1w,1M',
            'before' => 'required|integer|min:1|max:4102444800',
        ]);
        $key = 'chart-history:binance_futures:mainnet:'.hash('sha256', json_encode($data));
        $result = Cache::remember($key, 3600, function () use ($data) {
            $response = Http::timeout(8)->get(
                rtrim(config('services.binance.futures_api_url', 'https://fapi.binance.com'), '/').'/fapi/v1/klines',
                ['symbol' => $data['symbol'], 'interval' => $data['timeframe'],
                    'endTime' => ((int) $data['before'] * 1000) - 1, 'limit' => 200]
            );
            // Fail explicitly; a rate limit/outage is not "no older history".
            $response->throw();
            $candles = collect($response->json())->filter(fn ($row) => is_array($row) && count($row) >= 7)
                ->filter(fn ($row) => (int) $row[0] < (int) $data['before'] * 1000)
                ->map(fn ($row) => [
                    'time' => (int) floor($row[0] / 1000), 'open' => (float) $row[1],
                    'high' => (float) $row[2], 'low' => (float) $row[3],
                    'close' => (float) $row[4], 'volume' => (float) $row[5],
                    'isClosed' => (int) $row[6] < (int) floor(microtime(true) * 1000),
                    'symbol' => $data['symbol'],
                ])->sortBy('time')->values()->all();
            return ['symbol' => $data['symbol'], 'timeframe' => $data['timeframe'],
                'exchange' => 'binance_futures', 'source' => 'historical', 'candles' => $candles];
        });
        return response()->json($result);
    }
}
