<?php

namespace App\Domain\Ops;

use App\Domain\Intelligence\AiTradingStateService;
use App\Jobs\KeepAliveUserStreamsJob;
use App\Jobs\OrdersReconcileJob;
use App\Jobs\SyncOrderStatusJob;
use App\Models\AiTradingConfig;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

class OperationalActionService
{
    public function __construct(
        private readonly KillSwitchService $killSwitch,
        private readonly AiTradingStateService $aiTradingState,
    ) {
    }

    public function execute(string $workspaceId, string $actionKey, array $payload = [], ?string $actorId = null): array
    {
        $result = match ($actionKey) {
            'pause_ai' => $this->pauseAi($workspaceId, $payload, $actorId),
            'resume_ai' => $this->resumeAi($workspaceId, $payload, $actorId),
            'activate_kill_switch' => $this->activateKillSwitch($workspaceId, $payload, $actorId),
            'deactivate_kill_switch' => $this->deactivateKillSwitch($workspaceId, $payload, $actorId),
            'request_reconcile_sweep' => $this->requestReconcileSweep($workspaceId, $payload, $actorId),
            'refresh_exchange_sync' => $this->refreshExchangeSync($workspaceId, $payload, $actorId),
            'disable_external_context' => $this->toggleExternalContext($workspaceId, false, $actorId),
            'enable_external_context' => $this->toggleExternalContext($workspaceId, true, $actorId),
            'force_readiness_check' => ['queued' => false, 'message' => 'Use the readiness endpoint or scheduled command.'],
            default => throw new \InvalidArgumentException(sprintf('Unsupported operational action [%s].', $actionKey)),
        };

        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'action' => 'ops_action:' . $actionKey,
            'actor_id' => $actorId,
            'actor_type' => $actorId ? 'user' : 'system',
            'metadata' => array_merge($payload, $result),
        ]);

        return array_merge([
            'action_key' => $actionKey,
        ], $result);
    }

    private function pauseAi(string $workspaceId, array $payload, ?string $actorId): array
    {
        $state = $this->aiTradingState->pause(
            $workspaceId,
            null,
            'manual_operator_pause',
            trim((string) ($payload['reason'] ?? 'operator requested pause')) ?: 'operator requested pause',
            $actorId,
            null,
            true,
            ['source' => 'ops_action']
        );

        return ['state' => $state->toArray()];
    }

    private function resumeAi(string $workspaceId, array $payload, ?string $actorId): array
    {
        $state = $this->aiTradingState->resume(
            $workspaceId,
            null,
            $actorId,
            trim((string) ($payload['reason'] ?? 'manual_operator_resume')) ?: 'manual_operator_resume'
        );

        return ['state' => $state->toArray()];
    }

    private function activateKillSwitch(string $workspaceId, array $payload, ?string $actorId): array
    {
        $state = $this->killSwitch->activate(
            $workspaceId,
            $actorId,
            trim((string) ($payload['reason'] ?? 'manual_operator_kill_switch')) ?: 'manual_operator_kill_switch'
        );

        return ['state' => $state];
    }

    private function deactivateKillSwitch(string $workspaceId, array $payload, ?string $actorId): array
    {
        $state = $this->killSwitch->deactivate(
            $workspaceId,
            $actorId,
            trim((string) ($payload['reason'] ?? 'manual_operator_resume')) ?: 'manual_operator_resume'
        );

        return ['state' => $state];
    }

    private function requestReconcileSweep(string $workspaceId, array $payload, ?string $actorId): array
    {
        $limit = max(1, (int) ($payload['limit'] ?? 100));
        $integrationId = isset($payload['integration_id']) ? (string) $payload['integration_id'] : null;
        SyncOrderStatusJob::dispatch($limit);
        OrdersReconcileJob::dispatch($integrationId);

        return [
            'queued' => true,
            'limit' => $limit,
            'integration_id' => $integrationId,
            'message' => 'Reconcile sweep queued.',
        ];
    }

    private function refreshExchangeSync(string $workspaceId, array $payload, ?string $actorId): array
    {
        $limit = max(1, (int) ($payload['limit'] ?? 100));
        SyncOrderStatusJob::dispatch($limit);
        KeepAliveUserStreamsJob::dispatch();

        return [
            'queued' => true,
            'limit' => $limit,
            'message' => 'Exchange sync refresh queued.',
        ];
    }

    private function toggleExternalContext(string $workspaceId, bool $enabled, ?string $actorId): array
    {
        $config = AiTradingConfig::query()->firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'id' => (string) Str::uuid(),
                'enabled' => false,
                'mode' => 'advisor',
                'provider' => 'heuristic',
                'exchange' => 'binance',
                'market_type' => 'spot',
            ]
        );

        $config->use_external_context = $enabled;
        $config->save();

        return [
            'enabled' => $enabled,
            'config' => $config->fresh()->toArray(),
        ];
    }
}
