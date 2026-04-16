<?php

namespace App\Domain\Trading;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Position;
use App\Models\PositionExitEvent;
use App\Models\PositionExitState;
use Illuminate\Support\Str;

class PositionExitEventService
{
    public function record(
        PositionExitState $state,
        string $eventType,
        array $payload = [],
        string $severity = 'info',
        ?string $source = null,
        ?string $correlationId = null,
        ?Order $order = null
    ): PositionExitEvent {
        $position = $state->position ?: Position::find($state->position_id);

        $event = PositionExitEvent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $state->workspace_id,
            'position_exit_state_id' => $state->id,
            'position_id' => $state->position_id,
            'order_id' => $order?->id,
            'symbol' => strtoupper((string) ($state->symbol ?: $position?->asset_code ?: $position?->symbol?->symbol ?: '')),
            'event_type' => $eventType,
            'severity' => $severity,
            'source' => $source,
            'correlation_id' => $correlationId,
            'payload_json' => $payload,
            'occurred_at' => now(),
        ]);

        $this->notify($state, $eventType, $payload, $severity, $order);

        return $event;
    }

    private function notify(PositionExitState $state, string $eventType, array $payload, string $severity, ?Order $order): void
    {
        $body = (string) ($payload['message']
            ?? $payload['reason']
            ?? str_replace('_', ' ', $eventType));

        Notification::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $state->workspace_id,
            'type' => 'position_exit',
            'content' => [
                'title' => 'Managed exit update',
                'body' => $body,
                'severity' => $severity,
                'event_type' => $eventType,
                'symbol' => $state->symbol,
                'position_id' => $state->position_id,
                'order_id' => $order?->id,
            ] + $payload,
        ]);
    }
}
