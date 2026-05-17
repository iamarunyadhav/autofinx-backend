<?php

namespace App\Http\Controllers\Api;

use App\Domain\FutureTrade\FutureTradeUserStreamService;
use App\Http\Controllers\Controller;
use App\Models\FutureTradeBotConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Externally-relayed Binance Futures user-stream events for the Future Trade
 * bot. Lets users push events from a remote WS worker (Heroku/Render/etc) into
 * the bot when the host can't keep a long-running PHP WS open.
 *
 * Security: requires X-User-Stream-Token header to match
 * USER_STREAM_INGEST_TOKEN — same shared secret used by the spot ingest endpoint.
 */
class FutureTradeUserStreamController extends Controller
{
    public function ingest(Request $request, FutureTradeUserStreamService $userStream): JsonResponse
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

        $configId = (string) $request->header('X-FT-Config-Id', $request->input('config_id', ''));
        $workspaceId = (string) $request->header('X-FT-Workspace-Id', $request->input('workspace_id', ''));

        $config = null;
        if ($configId !== '') {
            $config = FutureTradeBotConfig::query()->whereKey($configId)->first();
        } elseif ($workspaceId !== '') {
            $config = FutureTradeBotConfig::query()
                ->where('workspace_id', $workspaceId)
                ->where('slot_index', 0)
                ->first();
        }

        if (! $config) {
            return response()->json(['message' => 'Future Trade config not found for ingest target.'], 404);
        }

        $event = $request->all();
        if (! is_array($event) || $event === []) {
            return response()->json(['message' => 'Empty user-stream event payload.'], 422);
        }
        $updated = $userStream->ingestEvent($config, $event);

        return response()->json([
            'ok' => true,
            'config_id' => (string) $config->id,
            'workspace_id' => (string) $config->workspace_id,
            'tradeUpdated' => $updated,
        ]);
    }

    public function snapshot(Request $request, string $workspace, FutureTradeUserStreamService $userStream): JsonResponse
    {
        $config = FutureTradeBotConfig::query()
            ->where('workspace_id', $workspace)
            ->where('slot_index', 0)
            ->firstOrFail();
        return response()->json($userStream->snapshot($config));
    }
}
