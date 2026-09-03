<?php

namespace App\Support;

use App\Models\ConfigVersion;
use Illuminate\Support\Str;

class ConfigVersioning
{
    public static function record(string $workspaceId, string $configKey, ?array $newValue = null, ?array $previousValue = null, ?string $actorId = null): ConfigVersion
    {
        return ConfigVersion::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'config_key' => $configKey,
            'new_value' => $newValue,
            'previous_value' => $previousValue,
            'actor_id' => $actorId,
        ]);
    }
}
