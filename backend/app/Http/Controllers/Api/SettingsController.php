<?php

namespace App\Http\Controllers\Api;

use App\Domain\Settings\SettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(Request $request, SettingsService $service)
    {
        $group = $request->query('group');
        $workspaceId = $request->query('workspace_id');
        abort_unless($group, 422, 'group required');
        return response()->json($service->get($group, $workspaceId));
    }

    public function update(Request $request, SettingsService $service)
    {
        $data = $request->validate([
            'workspace_id' => 'nullable|uuid',
            'settings' => 'required|array',
        ]);
        $service->upsert($data['settings'], $data['workspace_id'] ?? null, $request->user()->id ?? null);
        return response()->json(['ok' => true]);
    }
}
