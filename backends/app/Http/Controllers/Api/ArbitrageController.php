<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExchangeConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Services\ExecutionService\Adapters\ExchangeAdapterFactory;

class ArbitrageController extends Controller
{
    public function opportunities(Request $request, string $workspaceId)
    {
        $symbols = $request->input('symbols', ['BTCUSDT', 'ETHUSDT']);
        $connections = ExchangeConnection::where('workspace_id', $workspaceId)
            ->where('is_enabled', true)
            ->get();

        $factory = app(ExchangeAdapterFactory::class);
        $results = [];

        foreach ($symbols as $symbol) {
            $quotes = [];
            foreach ($connections as $connection) {
                try {
                    $adapter = $factory->makeFromConnection($connection);
                    $ticker = $adapter->fetchTicker($symbol);
                    $price = (float) ($ticker['price'] ?? 0);
                    if ($price > 0) {
                        $quotes[] = [
                            'connection_id' => $connection->id,
                            'connection' => $connection->name,
                            'provider' => $connection->provider,
                            'price' => $price,
                        ];
                    }
                } catch (\Throwable $e) {
                    // skip failing provider
                }
            }

            if (count($quotes) < 2) {
                continue;
            }

            usort($quotes, fn ($a, $b) => $a['price'] <=> $b['price']);
            $buy = $quotes[0];
            $sell = $quotes[count($quotes) - 1];
            $spread = $sell['price'] - $buy['price'];
            $spreadPercent = $spread / $buy['price'] * 100;

            $results[] = [
                'symbol' => $symbol,
                'buy' => $buy,
                'sell' => $sell,
                'spread' => round($spread, 4),
                'spread_percent' => round($spreadPercent, 4),
            ];
        }

        return response()->json($results);
    }

    public function execute(Request $request, string $workspaceId)
    {
        $data = $request->validate([
            'symbol' => 'required|string',
            'buy_connection_id' => 'required|uuid|exists:exchange_connections,id',
            'sell_connection_id' => 'required|uuid|exists:exchange_connections,id',
            'quantity' => 'required|numeric|min:0.0001',
        ]);

        // For now, just acknowledge the intent; real execution would place hedged orders.
        return response()->json([
            'status' => 'queued',
            'reference' => 'arb_' . Str::random(8),
            'symbol' => $data['symbol'],
            'quantity' => $data['quantity'],
            'buy_connection_id' => $data['buy_connection_id'],
            'sell_connection_id' => $data['sell_connection_id'],
        ], 202);
    }
}
