<?php

namespace App\Domain\Signals;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebhookDispatcherService
{
    public function dispatchForWorkspace(string $workspaceId, array $payload): void
    {
        $hooks = Webhook::where('workspace_id', $workspaceId)->where('enabled', true)->get();
        foreach ($hooks as $hook) {
            $this->send($hook, $payload);
        }
    }

    public function send(Webhook $hook, array $payload): WebhookDelivery
    {
        $delivery = WebhookDelivery::create([
            'id' => (string) Str::uuid(),
            'webhook_id' => $hook->id,
            'status' => 'pending',
            'payload' => $payload,
        ]);

        try {
            $resp = Http::withHeaders([
                'X-Autofinx-Signature' => $this->signature($hook, $payload),
            ])->post($hook->target_url, $payload);

            $delivery->status = $resp->successful() ? 'delivered' : 'failed';
            $delivery->response = ['status' => $resp->status(), 'body' => $resp->body()];
            $delivery->delivered_at = now();
            $delivery->save();
        } catch (\Throwable $e) {
            $delivery->status = 'failed';
            $delivery->response = ['error' => $e->getMessage()];
            $delivery->delivered_at = now();
            $delivery->save();
        }

        return $delivery;
    }

    private function signature(Webhook $hook, array $payload): string
    {
        $secret = $hook->secret_encrypted ?? '';
        return hash_hmac('sha256', json_encode($payload), $secret);
    }
}
