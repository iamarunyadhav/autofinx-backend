<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $ownerRole = Role::firstOrCreate(['name' => 'owner']);
        $user->assignRole($ownerRole);

        $workspace = Workspace::create([
            'id' => (string) Str::uuid(),
            'name' => "{$user->name}'s Workspace",
            'timezone' => 'Asia/Colombo',
        ]);

        WorkspaceMember::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $token = $user->createToken('auth')->plainTextToken;

        $this->logAudit($request, $user, 'user.register', ['workspace' => $workspace->id]);

        return response()->json([
            'token' => $token,
            'user' => $user,
            'workspace' => $workspace,
        ], 201);
    }

    public function login(Request $request, TwoFactorAuthenticationProvider $provider)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'code' => 'sometimes|string',
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $hasTwoFactor = ! empty($user->two_factor_secret) && ! empty($user->two_factor_confirmed_at);
        if ($hasTwoFactor) {
            $code = $credentials['code'] ?? null;
            $secret = Crypt::decryptString($user->two_factor_secret);
            if (! $code || ! $provider->verify($secret, $code)) {
                throw ValidationException::withMessages([
                    'code' => ['Two-factor code required or invalid.'],
                ]);
            }
        } elseif (env('ENFORCE_2FA_LOGIN', false)) {
            $code = $credentials['code'] ?? null;
            $expected = env('TEST_OTP', '000000');
            if (! $code || $code !== $expected) {
                throw ValidationException::withMessages([
                    'code' => ['Two-factor code required or invalid.'],
                ]);
            }
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth')->plainTextToken;

        $this->logAudit($request, $user, 'user.login');

        return response()->json([
            'token' => $token,
            'user' => $user,
        ]);
    }

    public function verify2fa(Request $request)
    {
        $code = $request->validate(['code' => 'required|string'])['code'];
        $expected = env('TEST_OTP', '000000');
        abort_unless($code === $expected, 403, 'Invalid 2FA');
        return response()->json(['status' => 'verified']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();
        $this->logAudit($request, $request->user(), 'user.logout');
        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('roles');
        $workspaces = Workspace::with('members')
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
            ->get();

        return response()->json([
            'user' => $user,
            'workspaces' => $workspaces,
        ]);
    }

    private function logAudit(Request $request, User $user, string $action, array $meta = []): void
    {
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $meta['workspace'] ?? null,
            'action' => $action,
            'actor_id' => $user->id,
            'actor_type' => 'user',
            'metadata' => $meta,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
