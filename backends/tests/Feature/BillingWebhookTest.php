<?php

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('processes stripe-like webhook and marks invoice paid', function () {
    $workspace = Workspace::factory()->create();
    $plan = Plan::factory()->create(['price' => 10]);
    $user = User::factory()->create();

    // create subscribe
    $subscribe = $this->actingAs($user)->postJson('/api/v1/billing/subscribe', [
        'workspace_id' => $workspace->id,
        'plan_id' => $plan->id,
        'gateway' => 'stripe',
        'currency' => 'USD',
    ])->assertCreated()
      ->json();

    $invoiceId = $subscribe['invoice']['id'];

    // simulate webhook
    $payload = [
        'id' => 'evt_' . Str::random(6),
        'type' => 'payment_intent.succeeded',
        'data' => [
            'object' => [
                'id' => 'pi_' . Str::random(6),
                'amount_total' => $plan->price * 100,
                'currency' => 'usd',
                'metadata' => [
                    'workspace_id' => $workspace->id,
                    'invoice_id' => $invoiceId,
                    'direction' => 'credit',
                ],
                'payment_status' => 'succeeded',
            ],
        ],
    ];

    $this->postJson('/api/v1/billing/webhook/stripe', $payload)->assertOk();

    $invoice = \App\Models\Invoice::find($invoiceId);
    expect($invoice->status)->toBe('paid');
});
