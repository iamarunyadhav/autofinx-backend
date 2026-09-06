<?php

namespace App\Domain\RatioBot;

use App\Domain\MarketData\MarketSnapshotService;
use App\Domain\Ops\OperationalBreakerService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;
use App\Models\User;
use InvalidArgumentException;
use RuntimeException;

/**
 * Whitelisted, symbol-scoped remediation actions callable from the
 * Diagnostics panel — deliberately NOT a generic "run this command"
 * executor (see spec section 6, Non-goals). Every fix takes only a
 * RatioBotConfig (always scoped to that bot's own workspace + symbol,
 * never workspace-wide/null), re-verifies the underlying condition
 * immediately before acting (never trusts a diagnose() result that might
 * be a few seconds stale), and writes an audit trail row.
 */
class RatioBotFixActionService
{
    public function __construct(
        private readonly MarketSnapshotService $snapshots,
        private readonly OperationalBreakerService $breakers,
        private readonly RatioBotDiagnosticsService $diagnostics,
    ) {
    }

    public function apply(RatioBotConfig $config, string $fixKey, ?User $actor): array
    {
        return match ($fixKey) {
            'clear_symbol_stale_breaker' => $this->clearSymbolStaleBreaker($config, $actor),
            default => throw new InvalidArgumentException("Unknown or unsupported fix_key: {$fixKey}"),
        };
    }

    private function clearSymbolStaleBreaker(RatioBotConfig $config, ?User $actor): array
    {
        $symbol = $config->normalizedSymbol();
        $workspaceId = (string) $config->workspace_id;

        // Guard: only clear if the feed is ACTUALLY fresh right now --
        // re-check, don't trust the diagnose() snapshot from a few seconds
        // ago. Never blind-clear.
        $snapshot = $this->snapshots->forRatioBot($config);
        if (! (bool) ($snapshot['is_fresh'] ?? false)) {
            throw new RuntimeException('Feed is not actually fresh right now -- refusing to clear the breaker.');
        }

        $this->breakers->clearStaleFeedBreaker(
            $workspaceId,
            [
                'reason' => 'manual_fix_via_diagnostics_panel',
                'actor_id' => $actor?->id,
                'symbol' => $symbol,
                'age_ms' => $snapshot['age_ms'] ?? null,
            ],
            'diagnostics_panel_manual_fix',
            $symbol,
        );

        RatioBotEvent::create([
            'ratio_bot_config_id' => $config->id,
            'event_type' => RatioBotEvent::EVENT_DIAGNOSTICS_FIX_APPLIED,
            'detail' => "Manually cleared the stale-feed breaker for {$symbol} via the Diagnostics panel.",
            'context' => ['fix_key' => 'clear_symbol_stale_breaker', 'symbol' => $symbol],
            'triggered_by_user_id' => $actor?->id,
            'occurred_at' => now(),
        ]);

        return $this->diagnostics->diagnose($config->fresh());
    }
}
