<?php

namespace App\Domain\Settings;

use App\Models\Setting;
use Illuminate\Support\Str;

class SettingsService
{
    public function get(string $group, ?string $workspaceId = null): array
    {
        return Setting::where('group', $group)
            ->where(function ($q) use ($workspaceId) {
                if ($workspaceId) {
                    $q->where('workspace_id', $workspaceId);
                } else {
                    $q->whereNull('workspace_id');
                }
            })
            ->get()
            ->mapWithKeys(fn ($s) => [$s->key => $s->value_json])
            ->toArray();
    }

    public function upsert(array $items, ?string $workspaceId, ?string $userId): void
    {
        foreach ($items as $group => $kv) {
            foreach ($kv as $key => $value) {
                Setting::updateOrCreate(
                    [
                        'workspace_id' => $workspaceId,
                        'group' => $group,
                        'key' => $key,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'value_json' => $value,
                        'encrypted' => false,
                        'updated_by' => $userId,
                    ]
                );
            }
        }
    }
}
