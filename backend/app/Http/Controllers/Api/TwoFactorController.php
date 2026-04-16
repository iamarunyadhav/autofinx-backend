<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

class TwoFactorController extends Controller
{
    public function setup(Request $request, TwoFactorAuthenticationProvider $provider)
    {
        $user = $request->user();

        $secret = $provider->generateSecretKey();
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit($user->workspace_id ?? null, $user->id, '2fa.setup.initiated');

        return response()->json([
            'secret' => $secret,
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function confirm(Request $request, TwoFactorAuthenticationProvider $provider)
    {
        $user = $request->user();

        $data = Validator::make($request->all(), [
            'code' => ['required', 'string'],
        ])->validate();

        $secret = $user->two_factor_secret ? Crypt::decryptString($user->two_factor_secret) : null;
        if (! $secret || ! $provider->verify($secret, $data['code'])) {
            return response()->json(['message' => 'Invalid two-factor code'], 422);
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->audit($user->workspace_id ?? null, $user->id, '2fa.confirmed');

        return response()->json(['status' => 'confirmed']);
    }

    public function disable(Request $request)
    {
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->audit($user->workspace_id ?? null, $user->id, '2fa.disabled');

        return response()->json(['status' => 'disabled']);
    }

    private function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))->map(fn () => Str::upper(Str::random(10)))->toArray();
    }

    private function audit(?string $workspaceId, ?string $actorId, string $action): void
    {
        AuditLog::create([
            'workspace_id' => $workspaceId,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_type' => 'user',
            'metadata' => [],
        ]);
    }
}
