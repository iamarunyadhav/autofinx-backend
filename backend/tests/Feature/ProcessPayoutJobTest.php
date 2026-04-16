<?php

use App\Jobs\ProcessPayout;
use App\Models\Payment;
use App\Models\Workspace;
use App\Support\PaymentGatewayService;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('keeps payout pending provider confirmation when gateway does not settle immediately', function () {
    $workspace = Workspace::factory()->create();

    $payment = Payment::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'invoice_id' => null,
        'payment_method_id' => null,
        'amount' => 100,
        'status' => 'pending',
        'provider' => 'bank_transfer',
        'metadata' => ['currency' => 'USD', 'direction' => 'debit'],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $gatewayMock = Mockery::mock(PaymentGatewayService::class);
    $gatewayMock->shouldReceive('payout')->andReturn([
        'reference' => 'bank_ref',
        'status' => 'provider_pending',
    ]);
    app()->instance(PaymentGatewayService::class, $gatewayMock);

    $job = new ProcessPayout($payment->id);
    $job->handle();

    $payment->refresh();
    expect($payment->status)->toBe('provider_pending');
});
