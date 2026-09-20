<?php

namespace App\Domain\Ops;

use App\Models\ExchangeConnection;
use App\Models\Setting;
use Illuminate\Support\Str;

class OperationalBreakerService
{
    public function providerState(?string $workspaceId, string $provider): array
    {
        $key = $this->providerKey($provider);

        return $this->loadState($workspaceId, $key) ?? $this->loadState(null, $key) ?? $this->defaultProviderState($provider);
    }

    public function isProviderOutageActive(?string $workspaceId, string $provider): bool
    {
        return (bool) ($this->providerState($workspaceId, $provider)['active'] ?? false);
    }

    public function activateProviderOutage(?string $workspaceId, string $provider, string $reason, string $source = 'system'): array
    {
        $state = array_merge($this->providerState($workspaceId, $provider), [
            'active' => true,
            'provider' => strtolower($provider),
            'reason' => trim($reason) !== '' ? trim($reason) : 'provider_outage',
            'source' => $source,
            'activated_at' => now()->toIso8601String(),
            'cleared_at' => null,
        ]);

        $this->persistState($workspaceId, $this->providerKey($provider), $state);

        return $state;
    }

    public function clearProviderOutage(?string $workspaceId, string $provider, string $reason = 'recovered', string $source = 'system'): array
    {
        $state = array_merge($this->providerState($workspaceId, $provider), [
            'active' => false,
            'provider' => strtolower($provider),
            'reason' => trim($reason) !== '' ? trim($reason) : 'recovered',
            'source' => $source,
            'cleared_at' => now()->toIso8601String(),
        ]);

        $this->persistState($workspaceId, $this->providerKey($provider), $state);

        return $state;
    }

    /**
     * $symbol is optional and defaults to the original workspace-wide
     * breaker (unchanged behavior for every existing caller). Pass a symbol
     * to check/mutate a breaker scoped to just that symbol, so one stale
     * symbol in a workspace doesn't gate every other symbol trading in the
     * same workspace.
     *
     * $marketType discriminates the per-symbol key ('spot' vs 'futures')
     * so the SAME symbol string (e.g. BTCUSDT) traded as a spot Ratio Bot
     * and as a futures Future Trade/Ratio Bot bot in the same workspace
     * never collide on one breaker entry -- a genuinely stale futures feed
     * must never be masked by a fresh spot feed for the same symbol, or
     * vice versa. Only meaningful when $symbol is provided; ignored for the
     * workspace-wide key (no symbol) which intentionally stays a single
     * coarse, market-type-agnostic advisory signal shared by every consumer
     * that reads it without a symbol (unchanged from before this fix).
     * Defaults to 'spot' to match every pre-existing caller's actual
     * context (verified caller-by-caller, not an unverified guess) --
     * every genuinely futures-context caller now passes 'futures'
     * explicitly.
     */
    public function staleFeedState(?string $workspaceId, ?string $symbol = null, string $marketType = 'spot'): array
    {
        $key = $this->staleFeedKey($symbol, $marketType);

        return $this->loadState($workspaceId, $key)
            ?? $this->loadState(null, $key)
            ?? $this->defaultStaleFeedState();
    }

    public function isStaleFeedBreakerActive(?string $workspaceId, ?string $symbol = null, string $marketType = 'spot'): bool
    {
        return (bool) ($this->staleFeedState($workspaceId, $symbol, $marketType)['active'] ?? false);
    }

    public function activateStaleFeedBreaker(?string $workspaceId, array $context = [], string $source = 'monitoring', ?string $symbol = null, string $marketType = 'spot'): array
    {
        $state = array_merge($this->staleFeedState($workspaceId, $symbol, $marketType), [
            'active' => true,
            'source' => $source,
            'activated_at' => now()->toIso8601String(),
            'context' => $context,
            'cleared_at' => null,
        ]);

        $this->persistState($workspaceId, $this->staleFeedKey($symbol, $marketType), $state);

        return $state;
    }

    public function clearStaleFeedBreaker(?string $workspaceId, array $context = [], string $source = 'monitoring', ?string $symbol = null, string $marketType = 'spot'): array
    {
        $state = array_merge($this->staleFeedState($workspaceId, $symbol, $marketType), [
            'active' => false,
            'source' => $source,
            'cleared_at' => now()->toIso8601String(),
            'context' => $context,
        ]);

        $this->persistState($workspaceId, $this->staleFeedKey($symbol, $marketType), $state);

        return $state;
    }

    private function staleFeedKey(?string $symbol, string $marketType = 'spot'): string
    {
        $symbol = $symbol !== null ? strtoupper(trim($symbol)) : '';

        if ($symbol === '') {
            return 'stale_feed_breaker';
        }

        $marketType = strtolower(trim($marketType));
        $marketType = $marketType !== '' ? $marketType : 'spot';

        return 'stale_feed_breaker:' . $marketType . ':' . $symbol;
    }

    public function streamState(ExchangeConnection $connection): array
    {
        return $this->loadState((string) $connection->workspace_id, $this->streamKey($connection->id))
            ?? $this->defaultStreamState($connection);
    }

    public function markStreamStarted(ExchangeConnection $connection, string $listenKey): array
    {
        $existing = $this->streamState($connection);
        $restarts = (int) ($existing['restart_count'] ?? 0);
        if (($existing['status'] ?? null) === 'error') {
            $restarts++;
        }

        $state = array_merge($existing, [
            'status' => 'running',
            'listen_key' => $listenKey,
            'last_started_at' => now()->toIso8601String(),
            'last_keepalive_at' => now()->toIso8601String(),
            'last_event_at' => $existing['last_event_at'] ?? null,
            'last_error' => null,
            'last_error_at' => null,
            'restart_count' => $restarts,
        ]);

        $this->persistState((string) $connection->workspace_id, $this->streamKey($connection->id), $state);

        return $state;
    }

    public function markStreamHeartbeat(ExchangeConnection $connection, string $reason = 'keepalive'): array
    {
        $state = array_merge($this->streamState($connection), [
            'status' => 'running',
            'last_keepalive_at' => now()->toIso8601String(),
            'last_event_source' => $reason,
            'last_error' => null,
            'last_error_at' => null,
        ]);

        $this->persistState((string) $connection->workspace_id, $this->streamKey($connection->id), $state);

        return $state;
    }

    public function markStreamEvent(ExchangeConnection $connection, array $context = []): array
    {
        $state = array_merge($this->streamState($connection), [
            'status' => 'running',
            'last_event_at' => now()->toIso8601String(),
            'last_event_source' => (string) ($context['source'] ?? 'event'),
            'last_event_type' => (string) ($context['event_type'] ?? ''),
            'last_error' => null,
            'last_error_at' => null,
        ]);

        $this->persistState((string) $connection->workspace_id, $this->streamKey($connection->id), $state);

        return $state;
    }

    public function markStreamStopped(ExchangeConnection $connection): array
    {
        $state = array_merge($this->streamState($connection), [
            'status' => 'stopped',
            'stopped_at' => now()->toIso8601String(),
        ]);

        $this->persistState((string) $connection->workspace_id, $this->streamKey($connection->id), $state);

        return $state;
    }

    public function markStreamError(ExchangeConnection $connection, string $message): array
    {
        $state = array_merge($this->streamState($connection), [
            'status' => 'error',
            'last_error' => $message,
            'last_error_at' => now()->toIso8601String(),
        ]);

        $this->persistState((string) $connection->workspace_id, $this->streamKey($connection->id), $state);

        return $state;
    }

    private function providerKey(string $provider): string
    {
        return 'provider_breaker:' . strtolower(trim($provider));
    }

    private function streamKey(string $connectionId): string
    {
        return 'stream_state:' . $connectionId;
    }

    private function persistState(?string $workspaceId, string $key, array $state): void
    {
        $existing = Setting::query()
            ->where('group', 'ops')
            ->where('key', $key)
            ->where(function ($query) use ($workspaceId): void {
                if ($workspaceId) {
                    $query->where('workspace_id', $workspaceId);
                } else {
                    $query->whereNull('workspace_id');
                }
            })
            ->first();

        Setting::updateOrCreate(
            [
                'workspace_id' => $workspaceId,
                'group' => 'ops',
                'key' => $key,
            ],
            [
                'id' => $existing?->id ?? (string) Str::uuid(),
                'value_json' => $state,
                'encrypted' => false,
            ]
        );
    }

    private function loadState(?string $workspaceId, string $key): ?array
    {
        $row = Setting::query()
            ->where('group', 'ops')
            ->where('key', $key)
            ->where(function ($query) use ($workspaceId): void {
                if ($workspaceId) {
                    $query->where('workspace_id', $workspaceId);
                } else {
                    $query->whereNull('workspace_id');
                }
            })
            ->first();

        return $row ? (array) $row->value_json : null;
    }

    private function defaultProviderState(string $provider): array
    {
        return [
            'active' => false,
            'provider' => strtolower($provider),
            'reason' => null,
            'source' => null,
            'activated_at' => null,
            'cleared_at' => null,
        ];
    }

    private function defaultStaleFeedState(): array
    {
        return [
            'active' => false,
            'source' => null,
            'activated_at' => null,
            'cleared_at' => null,
            'context' => [],
        ];
    }

    private function defaultStreamState(ExchangeConnection $connection): array
    {
        return [
            'connection_id' => $connection->id,
            'provider' => strtolower((string) ($connection->exchange ?: $connection->provider)),
            'status' => 'idle',
            'listen_key' => null,
            'restart_count' => 0,
            'last_started_at' => null,
            'last_keepalive_at' => null,
            'last_event_at' => null,
            'last_event_source' => null,
            'last_event_type' => null,
            'last_error' => null,
            'last_error_at' => null,
            'stopped_at' => null,
        ];
    }
}
