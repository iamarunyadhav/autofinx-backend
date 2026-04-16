<?php

namespace App\Domain\Advanced;

use App\Models\OrderRoute;
use App\Models\Venue;
use Illuminate\Support\Str;

class SmartOrderRouter
{
    /**
     * Simplified routing: pick first enabled venue with best (lowest) ask or fallback to demo.
     * Expects quote array: [['venue'=>'binance','bid'=>..., 'ask'=>..., 'latency_ms'=>...], ...]
     */
    public function chooseVenue(string $workspaceId, string $orderId, array $quotes): ?OrderRoute
    {
        if (empty($quotes)) {
            return null;
        }

        $enabledVenues = Venue::whereIn('name', array_column($quotes, 'venue'))
            ->where('enabled', true)
            ->get()
            ->keyBy('name');

        $best = null;
        foreach ($quotes as $q) {
            if (!$enabledVenues->has($q['venue'])) {
                continue;
            }
            if ($best === null || ($q['ask'] ?? INF) < ($best['ask'] ?? INF)) {
                $best = $q;
            }
        }

        if (!$best) {
            $demo = Venue::where('name', 'demo')->first();
            if (!$demo) {
                return null;
            }
            return OrderRoute::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'order_id' => $orderId,
                'chosen_venue_id' => $demo->id,
                'reason_json' => ['reason' => 'fallback_demo'],
                'quote_json' => null,
            ]);
        }

        $venue = $enabledVenues->get($best['venue']);
        return OrderRoute::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'order_id' => $orderId,
            'chosen_venue_id' => $venue->id,
            'reason_json' => ['reason' => 'best_ask', 'ask' => $best['ask'], 'latency_ms' => $best['latency_ms'] ?? null],
            'quote_json' => $best,
        ]);
    }
}
