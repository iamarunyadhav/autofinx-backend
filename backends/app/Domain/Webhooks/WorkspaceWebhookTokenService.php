<?php

namespace App\Domain\Webhooks;

use App\Models\WorkspaceWebhookToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class WorkspaceWebhookTokenService
{
    public function matches(Request $request, string $workspace, array $payload): bool
    {
        $provided = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            [$request->bearerToken(), $request->header('X-Webhook-Token'), $payload['token'] ?? null, $request->query('token')]
        )));

        $record = WorkspaceWebhookToken::query()
            ->where('workspace_id', $workspace)
            ->where('scope', WorkspaceWebhookToken::SCOPE_TRADINGVIEW)
            ->first();

        if ($record) {
            return collect($provided)->contains(fn (string $token): bool => Hash::check($token, $record->token_hash));
        }

        if (! config('future_trade.allow_global_webhook_token_fallback', true)) {
            return false;
        }

        $global = trim((string) config('future_trade.pine_webhook_token', ''));

        return $global !== '' && collect($provided)->contains(fn (string $token): bool => hash_equals($global, $token));
    }

    /** Returns the plaintext token once; only its one-way hash is persisted. */
    public function rotate(string $workspace, ?string $actorId): array
    {
        $plain = 'afx_'.Str::random(48);
        $record = WorkspaceWebhookToken::query()->updateOrCreate(
            ['workspace_id' => $workspace, 'scope' => WorkspaceWebhookToken::SCOPE_TRADINGVIEW],
            ['token_hash' => Hash::make($plain), 'token_hint' => substr($plain, -8), 'rotated_at' => now(), 'rotated_by' => $actorId]
        );

        return ['token' => $plain, 'hint' => $record->token_hint, 'rotated_at' => $record->rotated_at?->toIso8601String()];
    }
}
