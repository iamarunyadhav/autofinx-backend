<?php

namespace App\Http\Controllers\Api;

use App\Domain\Exchange\BinanceUserStreamService;
use App\Domain\Trading\ReconciliationService;
use App\Http\Controllers\Controller;
use App\Models\ExchangeConnection;
use Illuminate\Http\Request;

class BinanceUserStreamController extends Controller
{
    public function start(ExchangeConnection $connection, BinanceUserStreamService $streams)
    {
        $payload = $streams->start($connection);
        return response()->json($payload);
    }

    public function keepAlive(ExchangeConnection $connection, BinanceUserStreamService $streams)
    {
        $listenKey = $streams->keepAlive($connection);
        return response()->json(['listen_key' => $listenKey]);
    }

    public function stop(ExchangeConnection $connection, BinanceUserStreamService $streams)
    {
        $streams->stop($connection);
        return response()->json(['ok' => true]);
    }

    public function ingest(Request $request, ReconciliationService $reconciliation)
    {
        $expectedToken = (string) env('USER_STREAM_INGEST_TOKEN', '');
        if ($expectedToken === '' && ! app()->environment(['local', 'testing'])) {
            return response()->json(['message' => 'User-stream ingest token is not configured.'], 503);
        }
        if ($expectedToken !== '') {
            $provided = (string) $request->header('X-User-Stream-Token', '');
            if (! hash_equals($expectedToken, $provided)) {
                return response()->json(['message' => 'Invalid ingest token.'], 401);
            }
        }

        $event = $request->all();
        $order = $reconciliation->ingestExchangeEvent($event);
        $connectionId = (string) ($request->header('X-Exchange-Connection-Id', $event['connection_id'] ?? ''));
        if ($connectionId !== '') {
            $connection = ExchangeConnection::find($connectionId);
            if ($connection) {
                app(BinanceUserStreamService::class)->markFrameActivity($connection, [
                    'source' => 'ingest',
                    'event_type' => (string) ($event['e'] ?? $event['event'] ?? $event['eventType'] ?? ''),
                ]);
            }
        }

        return response()->json([
            'ok' => true,
            'matched_order_id' => $order?->id,
        ]);
    }
}
