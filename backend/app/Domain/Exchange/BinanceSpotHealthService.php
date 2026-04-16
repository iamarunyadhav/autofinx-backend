<?php

namespace App\Domain\Exchange;

use App\Domain\Ops\OperationalBreakerService;
use App\Models\RiskEvent;
use App\Models\Setting;
use Illuminate\Support\Str;

class BinanceSpotHealthService
{
    public function __construct(
        private readonly OperationalBreakerService $breakers,
    ) {
    }

    public function snapshot(string $workspaceId): array
    {
        return $this->load($workspaceId) ?? [
            'provider' => 'binance',
            'consecutive_rest_failures' => 0,
            'last_rest_failure_at' => null,
            'last_rest_success_at' => null,
            'last_error' => null,
        ];
    }

    public function recordSuccess(string $workspaceId, array $context = []): array
    {
        $state = array_merge($this->snapshot($workspaceId), [
            'consecutive_rest_failures' => 0,
            'last_rest_success_at' => now()->toIso8601String(),
            'last_error' => null,
            'context' => $context,
        ]);

        $this->persist($workspaceId, $state);
        $this->breakers->clearProviderOutage($workspaceId, 'binance', 'rest_ok', 'rest_health');

        return $state;
    }

    public function recordFailure(string $workspaceId, string $message, array $context = []): array
    {
        $state = $this->snapshot($workspaceId);
        $failures = max(0, (int) ($state['consecutive_rest_failures'] ?? 0)) + 1;

        $state = array_merge($state, [
            'consecutive_rest_failures' => $failures,
            'last_rest_failure_at' => now()->toIso8601String(),
            'last_error' => $message,
            'context' => $context,
        ]);

        $this->persist($workspaceId, $state);

        if ($failures >= 3) {
            $this->breakers->activateProviderOutage($workspaceId, 'binance', 'repeated_rest_failures', 'rest_health');
            RiskEvent::create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'type' => 'exchange_unhealthy',
                'severity' => 'critical',
                'reason_json' => [
                    'provider' => 'binance',
                    'reason' => 'repeated_rest_failures',
                    'last_error' => $message,
                    'failure_count' => $failures,
                ],
                'triggered_at' => now(),
            ]);
        }

        return $state;
    }

    private function persist(string $workspaceId, array $state): void
    {
        $existingId = Setting::query()
            ->where('workspace_id', $workspaceId)
            ->where('group', 'ops')
            ->where('key', 'binance_rest_health')
            ->value('id');

        Setting::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'group' => 'ops',
                'key' => 'binance_rest_health',
            ],
            [
                'id' => $existingId ?: (string) Str::uuid(),
                'value_json' => $state,
                'encrypted' => false,
            ]
        );
    }

    private function load(string $workspaceId): ?array
    {
        $row = Setting::query()
            ->where('workspace_id', $workspaceId)
            ->where('group', 'ops')
            ->where('key', 'binance_rest_health')
            ->first();

        return $row ? (array) $row->value_json : null;
    }
}
