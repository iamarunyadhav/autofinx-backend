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
}
