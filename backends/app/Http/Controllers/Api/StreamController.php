<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketTick;
use Illuminate\Http\Request;

class StreamController extends Controller
{
    public function prices(Request $request)
    {
        $symbols = $request->input('symbols', []);
        $symbols = is_array($symbols) ? $symbols : explode(',', (string) $symbols);
        $symbols = array_filter(array_map('trim', $symbols));

        $stream = function () use ($symbols) {
            for ($i = 0; $i < 10; $i++) {
                $query = MarketTick::orderByDesc('occurred_at')->limit(50);
                if (!empty($symbols)) {
                    $query->whereIn('symbol', $symbols);
                }
                $ticks = $query->get()->map(function ($tick) {
                    return [
                        'symbol' => $tick->symbol,
                        'price' => (float) $tick->price,
                        'change24h' => 0,
                        'change24hPercent' => 0,
                    ];
                });

                echo 'data: ' . json_encode(['ticks' => $ticks]) . "\n\n";
                ob_flush();
                flush();
                sleep(2);
            }
        };

        return response()->stream($stream, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
        ]);
    }
}
