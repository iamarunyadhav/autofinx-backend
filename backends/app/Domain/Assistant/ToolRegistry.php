<?php

namespace App\Domain\Assistant;

use App\Models\AuditLog;
use App\Models\ConfigVersion;
use App\Models\StrategyInstance;
use App\Models\StrategyParam;
use App\Models\WorkspaceMember;
use Illuminate\Support\Str;

class ToolRegistry
{
    /**
     * @return string[]
     */
    private function defaultAllowlist(): array
    {
        return ['get_portfolio_summary','explain_last_trade','change_strategy_param','toggle_strategy','generate_strategy_code'];
    }

    public function isAllowed(string $tool): bool
    {
        $allow = array_filter(explode(',', env('ASSISTANT_TOOL_ALLOWLIST', implode(',', $this->defaultAllowlist()))));
        return in_array($tool, $allow, true);
    }

    public function isMutationTool(string $tool): bool
    {
        return in_array($tool, ['toggle_strategy', 'change_strategy_param', 'generate_strategy_code'], true);
    }

    public function execute(string $tool, array $params, $user): array
    {
        return match ($tool) {
            'toggle_strategy' => $this->toggleStrategy($params, $user),
            'change_strategy_param' => $this->changeParam($params, $user),
            default => ['ok' => true, 'result' => 'ok'],
        };
    }

    private function toggleStrategy(array $params, $user): array
    {
        $instance = StrategyInstance::find($params['instance_id'] ?? null);
        if (!$instance) {
            return ['ok' => false, 'error' => 'forbidden', 'status_code' => 403];
        }
        if (!$this->userCan($user->id, $instance->workspace_id)) {
            return ['ok' => false, 'error' => 'forbidden', 'status_code' => 403];
        }
        $instance->status = $params['status'] ?? 'paused';
        $instance->save();
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $instance->workspace_id,
            'action' => 'assistant.toggle_strategy',
            'actor_id' => $user->id,
            'actor_type' => 'user',
            'metadata' => ['instance_id' => $instance->id, 'status' => $instance->status],
        ]);
        return ['ok' => true, 'status' => $instance->status];
    }

    private function changeParam(array $params, $user): array
    {
        $instanceId = $params['instance_id'] ?? null;
        $key = $params['key'] ?? null;
        $value = $params['value'] ?? null;
        if (!$instanceId || !$key) {
            return ['ok' => false, 'error' => 'invalid_params', 'status_code' => 422];
        }
        $instance = StrategyInstance::find($instanceId);
        if (!$instance) {
            return ['ok' => false, 'error' => 'forbidden', 'status_code' => 403];
        }
        if (!$this->userCan($user->id, $instance->workspace_id)) {
            return ['ok' => false, 'error' => 'forbidden', 'status_code' => 403];
        }
        StrategyParam::updateOrCreate(
            ['instance_id' => $instanceId, 'key' => $key],
            ['value' => $value]
        );
        ConfigVersion::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $instance->workspace_id,
            'config_key' => 'strategy_param.' . $instanceId . '.' . $key,
            'new_value' => $value,
            'previous_value' => null,
        ]);
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $instance->workspace_id,
            'action' => 'assistant.change_strategy_param',
            'actor_id' => $user->id,
            'actor_type' => 'user',
            'metadata' => ['instance_id' => $instanceId, 'key' => $key],
        ]);
        return ['ok' => true, 'updated' => true];
    }

    private function userCan(string $userId, string $workspaceId): bool
    {
        $role = WorkspaceMember::where('workspace_id', $workspaceId)->where('user_id', $userId)->value('role');
        return in_array($role, ['owner', 'admin', 'trader']);
    }
}
