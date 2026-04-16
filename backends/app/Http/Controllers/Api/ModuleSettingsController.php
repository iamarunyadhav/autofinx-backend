<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ModuleSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ModuleSettingsController extends Controller
{
    public function index(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        return ModuleSetting::when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))->get();
    }

    public function update(Request $request, string $moduleKey)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'mode' => 'required|string',
            'settings_json' => 'array|nullable',
        ]);

        $setting = ModuleSetting::updateOrCreate(
            ['workspace_id' => $data['workspace_id'], 'module_key' => $moduleKey],
            [
                'id' => ModuleSetting::where('workspace_id', $data['workspace_id'])->where('module_key', $moduleKey)->first()?->id ?? (string) Str::uuid(),
                'mode' => $data['mode'],
                'settings_json' => $data['settings_json'] ?? [],
            ]
        );

        return response()->json($setting);
    }
}
