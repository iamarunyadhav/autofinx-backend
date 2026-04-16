<?php

namespace App\Domain\Trading;

use App\Domain\Monitoring\EventStoreService;
use App\Models\LiveExecutionEvent;
use App\Models\Notification;
use App\Models\Order;
use App\Models\RiskEvent;
use Illuminate\Support\Str;

class LiveExecutionEventService
{
    public function __construct(
        private readonly EventStoreService $eventStore,
    ) {
    }

    public function recordOrderEvent(Order $order, string $eventType, array $payload = [], string $severity = 'info', ?string $source = null, ?string $correlationId = null): LiveExecutionEvent
    {
        $event = LiveExecutionEvent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $order->workspace_id,
            'order_id' => $order->id,
            'integration_id' => $order->integration_id,
            'provider' => $order->provider,
            'exchange' => $order->exchange,
            'symbol' => strtoupper((string) ($order->asset_code ?: $order->symbol?->symbol ?: '')),
            'event_type' => $eventType,
            'severity' => $severity,
            'source' => $source,
            'correlation_id' => $correlationId ?: ($order->trace_id ?: null),
            'payload_json' => $payload,
            'occurred_at' => now(),
        ]);

        $this->eventStore->append((string) $order->workspace_id, 'live_execution.' . $eventType, array_merge([
            'order_id' => $order->id,
            'symbol' => strtoupper((string) ($order->asset_code ?: '')),
            'provider' => $order->provider,
            'severity' => $severity,
        ], $payload));

        return $event;
    }

    public function recordAnomaly(Order $order, string $type, string $message, array $reason = [], string $severity = 'warning', ?string $source = null): void
    {
        RiskEvent::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $order->workspace_id,
            'type' => $type,
            'severity' => $severity,
            'reason_json' => array_merge([
                'order_id' => $order->id,
                'symbol' => strtoupper((string) ($order->asset_code ?: '')),
                'message' => $message,
            ], $reason),
            'triggered_at' => now(),
        ]);

        $this->recordOrderEvent($order, $type, array_merge(['message' => $message], $reason), $severity, $source);
        $this->notify($order, $message, $severity, $type);
    }

    public function notify(Order $order, string $message, string $severity = 'info', string $type = 'live_execution'): void
    {
        Notification::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $order->workspace_id,
            'type' => $type,
            'content' => [
                'title' => 'Live execution update',
                'body' => $message,
                'severity' => $severity,
                'order_id' => $order->id,
                'symbol' => strtoupper((string) ($order->asset_code ?: '')),
                'status' => $order->status,
            ],
        ]);
    }
}
