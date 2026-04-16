<?php

namespace App\Domain\Ops;

use App\Models\AuditLog;
use App\Models\Bot;
use App\Models\Setting;
use App\Models\StrategyInstance;
use Illuminate\Support\Str;
use RuntimeException;

class KillSwitchService
{
    public function activate(string $workspaceId, ?string $actorId, string $reason): array
    {
        $state = array_merge($this->state($workspaceId), [
            'active' => true,
            'reason' => trim($reason) !== '' ? $reason : 'manual',
            'activated_at' => now()->toIso8601String(),
            'activated_by' => $actorId,
            'deactivated_at' => null,
            'deactivated_by' => null,
            'open_order_cancellation' => 'deferred',
        ]);

        $this->persistState($workspaceId, $state, $actorId);
        $this->pauseWorkspaceAutomation($workspaceId);

        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'action' => 'kill_switch_activated',
            'actor_id' => $actorId,
            'actor_type' => 'system',
            'metadata' => $state,
        ]);

        return $state;
    }

    public function deactivate(string $workspaceId, ?string $actorId, string $reason = 'manual_resume'): array
    {
        $state = array_merge($this->state($workspaceId), [
            'active' => false,
            'reason' => trim($reason) !== '' ? $reason : 'manual_resume',
            'deactivated_at' => now()->toIso8601String(),
            'deactivated_by' => $actorId,
            'open_order_cancellation' => 'deferred',
        ]);

        $this->persistState($workspaceId, $state, $actorId);

        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'action' => 'kill_switch_deactivated',
            'actor_id' => $actorId,
            'actor_type' => 'system',
            'metadata' => $state,
        ]);

        return $state;
    }

    public function isActive(?string $workspaceId = null): bool
    {
        if ((bool) config('risk.kill_switch', false)) {
            return true;
        }

        return (bool) ($this->state($workspaceId)['active'] ?? false);
    }

    public function assertNotActive(string $workspaceId): void
    {
        if ($this->isActive($workspaceId)) {
            throw new RuntimeException('Kill switch is active for this workspace.');
        }
    }

    public function state(?string $workspaceId = null): array
    {
        $workspaceState = $workspaceId ? $this->loadState($workspaceId) : null;
        if ($workspaceState !== null) {
            return $workspaceState;
        }

        return $this->loadState(null) ?? $this->defaultState();
    }

    private function pauseWorkspaceAutomation(string $workspaceId): void
    {
        StrategyInstance::where('workspace_id', $workspaceId)
            ->where('mode', 'live')
            ->whereIn('status', ['running', 'RUNNING', 'idle'])
            ->update(['status' => 'paused']);

        Bot::where('workspace_id', $workspaceId)
            ->where('is_live', true)
            ->whereIn('status', ['RUNNING', 'running'])
            ->update(['status' => 'PAUSED']);
    }

    private function persistState(string $workspaceId, array $state, ?string $actorId): void
    {
        $existing = Setting::query()
            ->where('workspace_id', $workspaceId)
            ->where('group', 'ops')
            ->where('key', 'kill_switch')
            ->first();

        Setting::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'group' => 'ops',
                'key' => 'kill_switch',
            ],
            [
                'id' => $existing?->id ?? (string) Str::uuid(),
                'value_json' => $state,
                'encrypted' => false,
                'updated_by' => $actorId,
            ]
        );
    }

    private function loadState(?string $workspaceId): ?array
    {
        $row = Setting::query()
            ->where('group', 'ops')
            ->where('key', 'kill_switch')
            ->where(function ($query) use ($workspaceId): void {
                if ($workspaceId) {
                    $query->where('workspace_id', $workspaceId);
                } else {
                    $query->whereNull('workspace_id');
                }
            })
            ->first();

        if (! $row) {
            return null;
        }

        return array_merge($this->defaultState(), (array) $row->value_json);
    }

    private function defaultState(): array
    {
        return [
            'active' => false,
            'reason' => null,
            'activated_at' => null,
            'activated_by' => null,
            'deactivated_at' => null,
            'deactivated_by' => null,
            'open_order_cancellation' => 'deferred',
        ];
    }
}
