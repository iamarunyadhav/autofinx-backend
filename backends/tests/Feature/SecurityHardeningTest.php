<?php

use App\Models\ApiToken;
use App\Models\PromotionApproval;
use App\Models\PromotionRequest;
use App\Models\Signal;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    putenv('ENFORCE_2FA_CRITICAL=false');
    $_ENV['ENFORCE_2FA_CRITICAL'] = 'false';
    $_SERVER['ENFORCE_2FA_CRITICAL'] = 'false';
});

test('critical api token management routes require confirmed two factor when enforcement is enabled', function () {
    $user = User::factory()->create();

    putenv('ENFORCE_2FA_CRITICAL=true');
    $_ENV['ENFORCE_2FA_CRITICAL'] = 'true';
    $_SERVER['ENFORCE_2FA_CRITICAL'] = 'true';

    $this->actingAs($user)->postJson('/api/v1/api-tokens', [
        'name' => 'Signals token',
        'scopes' => ['read:signals'],
    ])->assertStatus(403)
        ->assertJsonPath('message', 'Enable and confirm two-factor authentication to perform this action.');
});

test('live promotion approval requires a valid two factor otp', function () {
    $workspace = Workspace::factory()->create();
    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey();
    $user = User::factory()->create([
        'password' => Hash::make('password123'),
    ]);
    $user->forceFill([
        'two_factor_secret' => Crypt::encryptString($secret),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $promotion = PromotionRequest::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'requested_by' => $user->id,
        'payload_json' => ['risk_acknowledged' => true],
        'status' => 'pending',
    ]);

    $this->actingAs($user)->postJson('/api/v1/live/promotion/approve', [
        'request_id' => $promotion->id,
        'otp' => '000000',
    ])->assertStatus(422)
        ->assertJsonPath('message', 'Invalid two-factor code.');

    $otp = $google2fa->getCurrentOtp($secret);

    $this->actingAs($user)->postJson('/api/v1/live/promotion/approve', [
        'request_id' => $promotion->id,
        'otp' => $otp,
    ])->assertOk()
        ->assertJsonPath('request.status', 'approved');

    expect(PromotionApproval::count())->toBe(1)
        ->and(PromotionRequest::findOrFail($promotion->id)->status)->toBe('approved');
});

test('api token scopes are enforced on signal routes', function () {
    $workspace = Workspace::factory()->create();

    Signal::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'symbol' => 'BTCUSDT',
        'action' => 'buy',
        'confidence' => 0.87,
        'regime' => 'trend',
        'strategy_name' => 'SOTR',
        'meta' => ['source' => 'test'],
    ]);

    $plainRead = 'atk_' . Str::random(40);
    $readToken = ApiToken::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Read Signals',
        'token_hash' => hash('sha256', $plainRead),
        'scopes' => ['read:signals'],
    ]);

    $this->withHeaders([
        'X-Api-Token' => $plainRead,
    ])->getJson("/api/v1/signals/history?workspace_id={$workspace->id}")
        ->assertOk()
        ->assertJsonCount(1);

    expect($readToken->fresh()->last_used_at)->not->toBeNull();

    $this->withHeaders([
        'X-Api-Token' => $plainRead,
    ])->postJson('/api/v1/signals/publish', [
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'action' => 'sell',
        'confidence' => 0.55,
    ])->assertStatus(403)
        ->assertJsonPath('message', 'API token is missing a required scope.');

    $plainWrite = 'atk_' . Str::random(40);
    ApiToken::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Write Signals',
        'token_hash' => hash('sha256', $plainWrite),
        'scopes' => ['write:signals'],
    ]);

    $this->withHeaders([
        'X-Api-Token' => $plainWrite,
    ])->postJson('/api/v1/signals/publish', [
        'workspace_id' => $workspace->id,
        'symbol' => 'ETHUSDT',
        'action' => 'sell',
        'confidence' => 0.55,
    ])->assertCreated()
        ->assertJsonPath('symbol', 'ETHUSDT');
});
