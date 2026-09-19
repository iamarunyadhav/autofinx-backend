<?php

namespace App\Domain\Nexus;

use App\Models\NexusEntry;

final class NexusStore
{
    public function put(string $workspace, string $kind, string $key, string $title, array $payload, string $status = 'OBSERVED', ?string $evidence = null): NexusEntry
    {
        return NexusEntry::updateOrCreate(
            ['workspace_id' => $workspace, 'kind' => $kind, 'fingerprint' => hash('sha256', $key)],
            ['title' => mb_substr($title, 0, 255), 'payload' => $payload, 'status' => $status, 'evidence_level' => $evidence],
        );
    }

    public function safety(): array
    {
        return ['autonomy' => min(2, max(0, (int) config('nexus.autonomy', 2))),
            'allow_code_write' => false, 'allow_auto_merge' => false, 'allow_deploy' => false,
            'allow_live_trading' => false, 'allow_risk_override' => false,
            'higher_autonomy' => 'Not implemented; configuration cannot grant execution permissions.'];
    }
}
