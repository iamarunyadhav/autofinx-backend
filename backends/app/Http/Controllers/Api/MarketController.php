<?php

namespace App\Http\Controllers\Api;

use App\Domain\Market\FxRateService;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Market\MetalsMarketDataService;
use App\Http\Controllers\Controller;
use App\Models\MarketCandle;
use App\Models\MarketTick;
use App\Models\Symbol;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketController extends Controller
{
    public function price(Request $request, MarketPriceCacheService $cache, MetalsMarketDataService $metals)
    {
        $data = $request->validate([
            'exchange' => 'nullable|string|max:24',
            'provider' => 'nullable|string|max:24',
            'symbol' => 'nullable|string|max:32',
            'asset_class' => 'nullable|string|in:CRYPTO,METALS,crypto,metals',
            'asset_code' => 'nullable|string|max:32',
            'quote' => 'nullable|string|max:8',
            'quote_currency' => 'nullable|string|max:8',
        ]);

        $provider = strtolower((string) ($data['provider'] ?? $data['exchange'] ?? ''));
        $assetClass = strtoupper((string) ($data['asset_class'] ?? ($provider === 'metal_broker' || $provider === 'metals' ? 'METALS' : 'CRYPTO')));
        if ($provider === '') {
            $provider = $assetClass === 'METALS' ? 'metal_broker' : 'binance';
        }
        $assetCode = strtoupper((string) ($data['asset_code'] ?? $data['symbol'] ?? ''));
        if ($assetCode === '') {
            return response()->json(['message' => 'asset_code or symbol is required'], 422);
        }

        if ($assetClass === 'METALS') {
            $quote = strtoupper((string) ($data['quote'] ?? $data['quote_currency'] ?? 'USD'));
            $payload = $metals->getPrice($assetCode, $quote, $provider);
            $price = $quote === 'LKR'
                ? (float) ($payload['lkr']['mid'] ?? 0)
                : (float) ($payload['usd']['mid'] ?? 0);

            return response()->json([
                ...$payload,
                'price' => $price,
                'quote' => $quote,
            ]);
        }

        $payload = $cache->getLatestPrice($provider, $assetCode);
        if (! $payload) {
            return response()->json(['message' => 'Price not available in cache'], 404);
        }

        return response()->json([
            ...$payload,
            'asset_class' => $assetClass,
            'asset_code' => $assetCode,
            'provider' => $provider,
            'quote' => strtoupper((string) ($data['quote'] ?? $data['quote_currency'] ?? 'USD')),
        ]);
    }

    public function fx(Request $request, FxRateService $fx)
    {
        $data = $request->validate([
            'base' => 'required|string|max:8',
            'quote' => 'required|string|max:8',
        ]);

        return response()->json($fx->quoteSnapshot(
            strtoupper((string) $data['base']),
            strtoupper((string) $data['quote'])
        ));
    }

    public function symbols()
    {
        $symbols = Symbol::all();

        if ($symbols->isEmpty()) {
            $seeds = [
                ['symbol' => 'BTCUSDT', 'type' => 'crypto'],
                ['symbol' => 'ETHUSDT', 'type' => 'crypto'],
                ['symbol' => 'AAPL', 'type' => 'equity'],
            ];

            foreach ($seeds as $seed) {
                Symbol::create($seed + ['id' => (string) Str::uuid()]);
            }

            $symbols = Symbol::all();
        }

        return response()->json($symbols);
    }

    public function candles(Request $request)
    {
        $data = $request->validate([
            'symbol' => 'required|string',
            'timeframe' => 'nullable|string',
        ]);

        $symbol = Symbol::firstOrCreate(
            ['symbol' => $data['symbol']],
            ['id' => (string) Str::uuid(), 'type' => 'crypto']
        );

        $timeframe = $data['timeframe'] ?? '1h';

        $candles = MarketCandle::where('symbol_id', $symbol->id)
            ->where('timeframe', $timeframe)
            ->orderByDesc('opens_at')
            ->limit(50)
            ->get();

        if ($candles->isEmpty()) {
            $price = 50000;
            for ($i = 0; $i < 50; $i++) {
                $openTime = now()->subMinutes($i * 60);
                $change = rand(-500, 500) / 100;
                $open = $price;
                $close = max(1, $price + $change);
                $high = max($open, $close) + rand(0, 100) / 100;
                $low = min($open, $close) - rand(0, 100) / 100;
                $volume = rand(10, 1000);

                MarketCandle::create([
                    'id' => (string) Str::uuid(),
                    'symbol_id' => $symbol->id,
                    'timeframe' => $timeframe,
                    'opens_at' => $openTime,
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                    'volume' => $volume,
                ]);

                $price = $close;
            }

            $candles = MarketCandle::where('symbol_id', $symbol->id)
                ->where('timeframe', $timeframe)
                ->orderByDesc('opens_at')
                ->limit(50)
                ->get();
        }

        return response()->json($candles);
    }

    public function ticks(Request $request)
    {
        $limit = (int) ($request->query('limit', 50));
        $ticks = MarketTick::with('symbol')
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->groupBy('symbol_id')
            ->map(function ($group) {
                $latest = $group->first();
                return [
                    'symbol' => $latest->symbol->symbol,
                    'price' => (float) $latest->price,
                    'bid' => (float) ($latest->bid ?? $latest->price),
                    'ask' => (float) ($latest->ask ?? $latest->price),
                    'volume' => (float) ($latest->volume ?? 0),
                    'occurred_at' => $latest->occurred_at,
                    'change24h' => 0,
                    'change24hPercent' => 0,
                ];
            })
            ->values();

        return response()->json($ticks);
    }
}
