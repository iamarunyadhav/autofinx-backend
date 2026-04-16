<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookConfigController extends Controller
{
    public function index(Request $request)
    {
        $workspaceId = $request->query('workspace_id');
        $q = Webhook::query();
        if ($workspaceId) {
            $q->where('workspace_id', $workspaceId);
        }
        return $q->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'workspace_id' => 'required|uuid',
            'target_url' => 'required|url',
            'secret' => 'nullable|string',
            'config' => 'array|nullable',
        ]);

        $wh = Webhook::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $data['workspace_id'],
            'target_url' => $data['target_url'],
            'secret_encrypted' => $data['secret'] ?? null,
            'config' => $data['config'] ?? [],
            'enabled' => true,
        ]);

        return response()->json($wh, 201);
    }

    public function update(Request $request, Webhook $webhook)
    {
        $data = $request->validate([
            'target_url' => 'sometimes|url',
            'secret' => 'nullable|string',
            'config' => 'array|nullable',
            'enabled' => 'boolean',
        ]);
        if (array_key_exists('secret', $data)) {
            $data['secret_encrypted'] = $data['secret'];
            unset($data['secret']);
        }
        $webhook->update($data);
        return response()->json($webhook);
    }

    public function test(Webhook $webhook)
    {
        $delivery = WebhookDelivery::create([
            'id' => (string) Str::uuid(),
            'webhook_id' => $webhook->id,
            'status' => 'queued',
            'payload' => ['test' => true],
        ]);
        return response()->json(['queued' => true, 'delivery_id' => $delivery->id]);
    }

    public function deliveries(Request $request)
    {
        $webhookId = $request->query('webhook_id');
        $q = WebhookDelivery::query();
        if ($webhookId) {
            $q->where('webhook_id', $webhookId);
        }
        return $q->orderByDesc('created_at')->limit(200)->get();
    }
}
