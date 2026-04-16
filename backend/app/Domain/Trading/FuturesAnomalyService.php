<?php

namespace App\Domain\Trading;

use App\Models\Notification;
use App\Models\RiskEvent;
use Illuminate\Support\Str;

class FuturesAnomalyService
{
    public function record(string $workspaceId, string $type, string $message, string $severity = 'warning', array $context = []): RiskEvent
    {
        $event = RiskEvent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'type' => $type,
            'severity' => $severity,
            'reason_json' => array_merge(['message' => $message], $context),
            'triggered_at' => now(),
        ]);

        Notification::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'type' => 'futures_anomaly',
            'content' => [
                'title' => 'Futures safety event',
                'body' => $message,
                'severity' => $severity,
                'type' => $type,
            ] + $context,
        ]);

        return $event;
    }
}
