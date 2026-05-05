<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PodiumWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        try {
            Log::info('podium.webhook.received', [
                'event_id' => $request->header('X-Request-Id')
                    ?? $request->header('X-Podium-Event-Id')
                    ?? $request->input('id'),
                'headers' => $request->headers->all(),
                'payload' => $request->all(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
    }
}
