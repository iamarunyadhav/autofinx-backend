<?php

namespace App\Http\Controllers\Api;

use App\Domain\Integrations\IntegrationManager;
use App\Http\Controllers\Controller;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IntegrationController extends Controller
{
    public function index(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        return Integration::when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))->get();
    }

    public function store(Request $request, IntegrationManager $manager)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'type' => 'required|string',
            'provider_key' => 'required|string',
            'display_name' => 'required|string',
            'config_json' => 'array|nullable',
            'secrets' => 'array|nullable',
        ]);
        $data['status'] = 'active';
        $integration = $manager->upsert($data, (string) Str::uuid());
        return response()->json($integration, 201);
    }

    public function update(Request $request, Integration $integration, IntegrationManager $manager)
    {
        $data = $request->validate([
            'config_json' => 'array|nullable',
            'status' => 'string|nullable',
            'secrets' => 'array|nullable',
        ]);
        $merged = array_merge($integration->toArray(), $data);
        $integration = $manager->upsert($merged, $integration->id);
        return response()->json($integration);
    }

    public function rotate(Request $request, Integration $integration, IntegrationManager $manager)
    {
        $data = $request->validate([
            'secrets' => 'required|array',
        ]);
        $integration = $manager->upsert(array_merge($integration->toArray(), ['secrets' => $data['secrets']]), $integration->id);
        return response()->json(['ok' => true]);
    }

    public function health(Integration $integration, IntegrationManager $manager)
    {
        $status = $manager->health($integration);
        return response()->json(['status' => $status]);
    }
}
