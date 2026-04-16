<?php

use App\Models\LedgerTransaction;
use App\Models\WebhookInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('webhook credits only once per idempotency key', function () {
    $workspaceId = (string) Str::uuid();
    \App\Models\Workspace::create(['id' => $workspaceId, 'name' => 'WS']);
    $payload = [
        'order_id' => 'ph_demo',
        'status_code' => 2,
        'payhere_amount' => 25,
        'payhere_currency' => 'USD',
        'workspace_id' => $workspaceId,
    ];
    $key = 'demo-idem-1';

    $this->postJson('/api/v1/billing/webhook/payhere', $payload, ['Idempotency-Key' => $key])->assertOk();
    $this->postJson('/api/v1/billing/webhook/payhere', $payload, ['Idempotency-Key' => $key])
        ->assertOk()
        ->assertJsonPath('duplicate', true);

    expect(WebhookInbox::count())->toBe(1);
    $inbox = WebhookInbox::first();
    expect($inbox->status)->toBe('processed');
    expect(LedgerTransaction::count())->toBe(1);
});
