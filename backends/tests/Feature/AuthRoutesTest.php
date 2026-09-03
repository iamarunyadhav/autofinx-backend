<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthRoutesTest extends TestCase
{
    public function test_user_can_register_and_login(): void
    {
        $email = 'newuser+' . Str::random(6) . '@example.com';
        $password = 'password123';

        // Register
        $register = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test User',
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertCreated();

        $this->assertArrayHasKey('token', $register->json());

        // Login
        $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_protected_route_requires_token(): void
    {
        $this->getJson('/api/v1/workspaces')->assertStatus(401);
    }

    public function test_me_returns_user_and_workspaces(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonStructure(['user', 'workspaces']);
    }

    public function test_login_requires_real_two_factor_code_for_confirmed_users(): void
    {
        $password = 'password123';
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey();

        $user = User::factory()->create([
            'email' => 'secured+' . Str::random(6) . '@example.com',
            'password' => Hash::make($password),
        ]);
        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
            'code' => '000000',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
            'code' => $google2fa->getCurrentOtp($secret),
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_user_can_update_and_fetch_their_profile(): void
    {
        $user = User::factory()->create(['name' => 'Original Name']);
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/auth/profile', [
                'full_name' => 'Updated Name',
                'phone' => '+1 555 0100',
                'timezone' => 'America/New_York',
                'preferred_language' => 'es',
            ])->assertOk()
                ->assertJsonPath('user.name', 'Updated Name')
                ->assertJsonPath('user.phone', '+1 555 0100')
                ->assertJsonPath('user.timezone', 'America/New_York')
                ->assertJsonPath('user.preferred_language', 'es');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated Name')
            ->assertJsonPath('user.phone', '+1 555 0100');
    }

    public function test_profile_update_ignores_a_blank_name_instead_of_clearing_it(): void
    {
        $user = User::factory()->create(['name' => 'Keep Me']);
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/auth/profile', ['full_name' => '', 'phone' => '555'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Keep Me')
            ->assertJsonPath('user.phone', '555');
    }

    public function test_user_can_change_password_with_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'old-password-123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])->assertOk();

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'new-password-456',
        ])->assertOk();
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'totally-wrong',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])->assertStatus(422)
                ->assertJsonValidationErrors(['current_password']);

        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_change_password_revokes_other_sessions_but_keeps_current_one(): void
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-123')]);
        $currentToken = $user->createToken('auth');
        $otherToken = $user->createToken('auth');

        $this->assertSame(2, $user->tokens()->count());

        $this->withToken($currentToken->plainTextToken)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'old-password-123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])->assertOk();

        $this->assertSame(1, $user->tokens()->count());
        $this->assertNotNull($user->tokens()->find($currentToken->accessToken->id));
        $this->assertNull($user->tokens()->find($otherToken->accessToken->id));
    }

    public function test_logout_with_all_flag_revokes_every_token(): void
    {
        $user = User::factory()->create();
        $tokenA = $user->createToken('auth')->plainTextToken;
        $user->createToken('auth');

        $this->assertSame(2, $user->tokens()->count());

        $this->withToken($tokenA)
            ->postJson('/api/v1/auth/logout', ['all' => true])
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }
}
