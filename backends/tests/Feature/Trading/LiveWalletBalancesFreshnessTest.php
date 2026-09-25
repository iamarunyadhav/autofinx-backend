<?php

use App\Models\Integration;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('live wallet balances endpoint refreshes a stale account from the real provider', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    $integration = liveWalletIntegration($workspace->id);
    $account = liveWalletAccount($workspace->id, $integration->id, [
        'balance' => 100,
        'available' => 100,
        'last_provider_sync_at' => null,
    ]);

    $this->actingAs($user);

    $calls = 0;
    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$calls) {
        if (str_contains($request->url(), '/fapi/v2/balance')) {
            $calls++;

            return Http::response([
                ['asset' => 'USDT', 'balance' => '555.00', 'availableBalance' => '540.00'],
            ], 200);
        }

        return Http::response([], 200);
    });

    $response = $this->getJson("/api/v1/workspaces/{$workspace->id}/wallet/accounts")->assertOk();

    expect($calls)->toBe(1);

    $body = collect($response->json())->firstWhere('id', $account->id);
    expect((float) $body['balance'])->toBe(555.0)
        ->and((float) $body['available'])->toBe(540.0);

    $account->refresh();
    expect((float) $account->balance)->toBe(555.0)
        ->and((float) $account->available)->toBe(540.0)
        ->and($account->metadata['last_provider_sync_at'])->not->toBeNull();
});

test('live wallet balances endpoint does not re-fetch an account refreshed within the rate-limit window', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    $integration = liveWalletIntegration($workspace->id);
    liveWalletAccount($workspace->id, $integration->id, [
        'balance' => 100,
        'available' => 100,
        'last_provider_sync_at' => now()->subSeconds(2)->toIso8601String(),
    ]);

    $this->actingAs($user);

    $calls = 0;
    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$calls) {
        if (str_contains($request->url(), '/fapi/v2/balance')) {
            $calls++;

            return Http::response([
                ['asset' => 'USDT', 'balance' => '999.00', 'availableBalance' => '999.00'],
            ], 200);
        }

        return Http::response([], 200);
    });

    $this->getJson("/api/v1/workspaces/{$workspace->id}/wallet/accounts")->assertOk();

    expect($calls)->toBe(0);
});

test('live wallet balances endpoint leaves accounts with no integration_id untouched', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    $account = WalletAccount::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'currency' => 'USDT',
        'type' => 'cash',
        'name' => 'USDT Wallet',
        'status' => 'active',
        'balance' => 42,
        'available' => 42,
        'locked_balance' => 0,
        'metadata' => ['mode' => 'paper'],
    ]);

    $this->actingAs($user);

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        if (str_contains($request->url(), '/fapi/v2/balance')) {
            throw new RuntimeException('Provider should never be called for a non-live-synced account.');
        }

        return Http::response([], 200);
    });

    $response = $this->getJson("/api/v1/workspaces/{$workspace->id}/wallet/accounts")->assertOk();

    $body = collect($response->json())->firstWhere('id', $account->id);
    expect((float) $body['balance'])->toBe(42.0);
});

test('live wallet balances endpoint returns last-known values for an account whose provider call fails, without breaking other accounts', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    $integration = liveWalletIntegration($workspace->id);

    $failingAccount = liveWalletAccount($workspace->id, $integration->id, [
        'currency' => 'BTC',
        'balance' => 1,
        'available' => 1,
        'last_provider_sync_at' => null,
    ]);
    $healthyAccount = liveWalletAccount($workspace->id, $integration->id, [
        'currency' => 'USDT',
        'balance' => 200,
        'available' => 200,
        'last_provider_sync_at' => null,
    ]);

    $this->actingAs($user);

    // Accounts are refreshed in currency order (BTC before USDT), so the
    // first /fapi/v2/balance call belongs to the BTC account and is made to
    // fail; the second call belongs to the USDT account and succeeds.
    $balanceCalls = 0;
    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$balanceCalls) {
        if (str_contains($request->url(), '/fapi/v2/balance')) {
            $balanceCalls++;
            if ($balanceCalls === 1) {
                return Http::response(['msg' => 'rate limited'], 429);
            }

            return Http::response([
                ['asset' => 'USDT', 'balance' => '250.00', 'availableBalance' => '245.00'],
            ], 200);
        }

        return Http::response([], 200);
    });

    $response = $this->getJson("/api/v1/workspaces/{$workspace->id}/wallet/accounts")->assertOk();

    $failingBody = collect($response->json())->firstWhere('id', $failingAccount->id);
    $healthyBody = collect($response->json())->firstWhere('id', $healthyAccount->id);

    expect((float) $failingBody['balance'])->toBe(1.0)
        ->and((float) $healthyBody['balance'])->toBe(250.0)
        ->and((float) $healthyBody['available'])->toBe(245.0);
});

test('live wallet balances endpoint bootstraps an untagged account via the workspace active integration when the workspace is in live mode', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    $integration = liveWalletIntegration($workspace->id);
    seedLiveWalletTradingSettings($workspace->id, $integration->id);

    // No integration_id in metadata -- exactly the "created before any live
    // trade bootstrapped it" case that left real accounts permanently stale.
    $account = WalletAccount::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'currency' => 'USDT',
        'type' => 'exchange',
        'name' => 'USDT Wallet',
        'status' => 'active',
        'wallet_type' => 'provider',
        'balance' => 66.84,
        'available' => 66.84,
        'locked_balance' => 0,
        'metadata' => [],
    ]);

    $this->actingAs($user);

    $calls = 0;
    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$calls) {
        if (str_contains($request->url(), '/fapi/v2/balance')) {
            $calls++;

            return Http::response([
                ['asset' => 'USDT', 'balance' => '312.50', 'availableBalance' => '300.00'],
            ], 200);
        }

        return Http::response([], 200);
    });

    $response = $this->getJson("/api/v1/workspaces/{$workspace->id}/wallet/accounts")->assertOk();

    expect($calls)->toBe(1);

    $body = collect($response->json())->firstWhere('id', $account->id);
    expect((float) $body['balance'])->toBe(312.5)
        ->and((float) $body['available'])->toBe(300.0);

    $account->refresh();
    expect((float) $account->balance)->toBe(312.5)
        ->and($account->metadata['integration_id'])->toBe($integration->id);
});

test('live wallet balances endpoint leaves an untagged account alone when the workspace is in paper mode', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceMember::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);

    // No trading-mode Setting seeded at all -> tradingState() falls back to
    // the app default, which is paper. An untagged account here has no real
    // integration to resolve, and that IS correct -- paper-mode workspaces
    // never have a real balance to reconcile against.
    $account = WalletAccount::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'currency' => 'USDT',
        'type' => 'exchange',
        'name' => 'USDT Wallet',
        'status' => 'active',
        'wallet_type' => 'provider',
        'balance' => 10000,
        'available' => 10000,
        'locked_balance' => 0,
        'metadata' => [],
    ]);

    $this->actingAs($user);

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        if (str_contains($request->url(), '/fapi/v2/balance')) {
            throw new RuntimeException('Provider should never be called for an untagged account in paper mode.');
        }

        return Http::response([], 200);
    });

    $response = $this->getJson("/api/v1/workspaces/{$workspace->id}/wallet/accounts")->assertOk();

    $body = collect($response->json())->firstWhere('id', $account->id);
    expect((float) $body['balance'])->toBe(10000.0);
    expect($account->fresh()->metadata['integration_id'] ?? null)->toBeNull();
});

function seedLiveWalletTradingSettings(string $workspaceId, string $integrationId): void
{
    foreach ([
        'trading_mode' => 'live',
        'live_enabled' => true,
        'preferred_provider' => 'binance',
        'preferred_integration_id' => $integrationId,
        'venue_environment' => 'live',
    ] as $key => $value) {
        \App\Models\Setting::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'group' => 'trading',
                'key' => $key,
            ],
            [
                'id' => (string) Str::uuid(),
                'value_json' => $value,
                'encrypted' => false,
            ]
        );
    }
}

function liveWalletIntegration(string $workspaceId): Integration
{
    return Integration::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'type' => 'exchange',
        'provider_key' => 'binance',
        'display_name' => 'Binance Live',
        'status' => 'active',
        'config_json' => ['environment' => 'live'],
        'secrets_encrypted' => Crypt::encryptString(json_encode([
            'api_key' => 'test-key',
            'api_secret' => 'test-secret',
        ])),
    ]);
}

function liveWalletAccount(string $workspaceId, string $integrationId, array $overrides = []): WalletAccount
{
    $currency = $overrides['currency'] ?? 'USDT';
    $lastSyncAt = array_key_exists('last_provider_sync_at', $overrides) ? $overrides['last_provider_sync_at'] : null;

    return WalletAccount::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspaceId,
        'currency' => $currency,
        'type' => 'exchange',
        'name' => strtoupper($currency) . ' Wallet',
        'status' => 'active',
        'wallet_type' => 'provider',
        'balance' => $overrides['balance'] ?? 0,
        'available' => $overrides['available'] ?? 0,
        'locked_balance' => $overrides['locked_balance'] ?? 0,
        'metadata' => array_filter([
            'provider' => 'binance',
            'integration_id' => $integrationId,
            'mode' => 'live',
            'last_provider_sync_at' => $lastSyncAt,
        ], fn ($v) => $v !== null),
    ]);
}
