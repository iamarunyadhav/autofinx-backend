<?php

namespace Tests\Feature;

use App\Models\Workspace;
use App\Models\WebhookInbox;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookIdempotencyTest extends TestCase
{
    public function test_webhook_is_idempotent_on_replay(): void
    {
        $workspace = Workspace::factory()->create();
        $payload = [
            'order_id' => 'ph_' . Str::random(8),
            'status_code' => 2,
            'payhere_amount' => 100,
            'payhere_currency' => 'USD',
            'workspace_id' => $workspace->id,
        ];
        $idempotencyKey = (string) Str::uuid();

        $first = $this->postJson('/api/v1/billing/webhook/payhere', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ])->assertOk();

        $second = $this->postJson('/api/v1/billing/webhook/payhere', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ])->assertOk();

        $this->assertDatabaseCount('webhooks_inbox', 1);
        $this->assertTrue((bool) ($first->json('ok') ?? false));
        $this->assertTrue((bool) ($second->json('ok') ?? false));
        $this->assertTrue((bool) ($second->json('duplicate') ?? false));
    }
}
