<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ops\FeatureFlagService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Platform-wide (not workspace-scoped) admin toggle surface for AI/
 * automation capability flags -- see FeatureFlagService::REGISTRY for the
 * full inventory. Consistent with this app's other non-workspace-scoped
 * admin surfaces (ModuleSettingsController is workspace-scoped because
 * module settings genuinely vary per workspace; these flags govern
 * platform-wide scheduled jobs/shadow-mode behavior, so a single trusted
 * operator toggling them for the whole platform matches this app's
 * single-owner/admin model).
 */
class FeatureFlagController extends Controller
{
    public function index(FeatureFlagService $service)
    {
        $flags = collect($service->all());

        return response()->json([
            'flags' => $flags->values(),
            'grouped' => $flags->groupBy('category')->map(fn ($group) => $group->values()),
        ]);
    }

    public function update(Request $request, string $key, FeatureFlagService $service)
    {
        if (! array_key_exists($key, FeatureFlagService::REGISTRY)) {
            return response()->json(['message' => 'Unknown feature flag key.'], 404);
        }

        $data = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $row = $service->set($key, (bool) $data['enabled'], auth()->id());

        return response()->json([
            'key' => $row->key,
            'enabled' => (bool) $row->enabled,
            'category' => $row->category,
            'label' => $row->label,
            'description' => $row->description,
            'default_value' => (bool) $row->default_value,
            'updated_at' => $row->updated_at,
            'updated_by' => $row->updated_by,
        ]);
    }
}
