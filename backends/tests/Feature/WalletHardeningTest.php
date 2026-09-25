<?php

use App\Models\Payment;
use App\Models\WalletAccount;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'billing.mode' => 'sandbox',
        'services.binance.api_url' => 'https://api.binance.local',
    ]);
});

it('disables legacy wallet topup route', function () {
    $workspace = Workspace::factory()->create();
    $user = \App\Models\User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/wallet/topup/initiate', [
        'workspace_id' => $workspace->id,
        'amount' => 25,
    ])->assertStatus(410);
});

it('creates deposit intent without immediately crediting wallet balance', function () {
    $workspace = Workspace::factory()->create();
    $user = \App\Models\User::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    $this->actingAs($user)->postJson('/api/v1/wallet/deposit/intent', [
        'workspace_id' => $workspace->id,
        'amount' => 50,
        'currency' => 'USD',
        'gateway' => 'bank_transfer',
        'environment' => 'sandbox',
    ])->assertCreated()
        ->assertJsonPath('checkout.status', 'pending_provider');

    $payment = Payment::first();
    expect($payment)->not->toBeNull()
        ->and($payment->status)->toBe(Payment::STATUS_PENDING_PROVIDER)
        ->and(WalletAccount::count())->toBe(0);
});

it('creates withdrawals as under review without trusting client kyc flags', function () {
    $workspace = Workspace::factory()->create();
    $user = \App\Models\User::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    WalletAccount::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'currency' => 'USD',
        'type' => 'cash',
        'name' => 'USD Wallet',
        'status' => 'active',
        'balance' => 100,
        'available' => 100,
        'locked_balance' => 0,
    ]);

    $this->actingAs($user)->postJson('/api/v1/wallet/withdraw/request', [
        'workspace_id' => $workspace->id,
        'amount' => 20,
        'currency' => 'USD',
        'method' => 'bank_transfer',
        'destination_meta' => ['account' => '1234567890'],
    ])->assertCreated()
        ->assertJsonPath('withdrawal.status', 'under_review');
});

it('requires explicit withdraw webhook configuration', function () {
    putenv('WITHDRAW_WEBHOOK_TOKEN');
    unset($_ENV['WITHDRAW_WEBHOOK_TOKEN'], $_SERVER['WITHDRAW_WEBHOOK_TOKEN']);

    $this->postJson('/api/v1/wallet/withdraw/webhook', [
        'withdrawal_id' => (string) Str::uuid(),
        'status' => 'completed',
    ])->assertStatus(503);
});

it('does not fallback-credit billing webhook failures', function () {
    $this->postJson('/api/v1/billing/webhook/payhere', [
        'order_id' => 'ph_fail',
        'status_code' => 2,
        'payhere_amount' => 25,
        'payhere_currency' => 'USD',
    ])->assertStatus(422);

    expect(Payment::count())->toBe(0);
});
