<?php

namespace App\Domain\Monitoring;

use App\Models\EventStore;
use Illuminate\Support\Str;

class EventStoreService
{
    public function append(?string $workspaceId, string $type, array $payload, ?\DateTimeInterface $at = null): EventStore
    {
        $occurred = $at ? \Carbon\CarbonImmutable::instance($at) : now();
        $hash = hash('sha256', $workspaceId . $type . json_encode($payload) . $occurred->toIso8601String());

        return EventStore::firstOrCreate(
            ['hash' => $hash],
            [
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'event_type' => $type,
                'payload_json' => $payload,
                'occurred_at' => $occurred,
            ]
        );
    }
}
