<?php

namespace App\Domain\RatioBot;

use App\Models\Position;
use App\Models\RatioBotConfig;
use App\Models\Symbol;
use Illuminate\Support\Carbon;

/**
 * Continuous liquidation-distance monitor for futures Ratio Bots (2026-09-06).
 *
 * Ratio Bot's core philosophy -- never sell at a loss, wait as long as it
 * takes for a profitable exit -- is unsafe on its own under leverage: the
 * exchange does not wait. Two other layers already exist and are reused
 * here rather than duplicated:
 *   - FuturesRiskService (via FuturesExecutionGuard) already blocks a BRAND
 *     NEW order whose estimated post-fill buffer is too thin, using a
 *     single workspace-wide threshold.
 *   - FuturesPositionSyncService already syncs the REAL exchange
 *     liquidationPrice into the `positions` table (via the
 *     `ratio-bots:reconcile` schedule, every few minutes).
 * What was missing, and what this service adds: nothing re-checks an
 * ALREADY-OPEN position's liquidation distance between fills using
 * per-bot-configurable thresholds, and nothing force-exits it if price
 * drifts toward liquidation while the bot is just "waiting for profit".
 */
class RatioBotLiquidationGuardService
{
    /**
     * Above this, synced position data is trusted as-is. Below it (but the
     * position is still open), we don't yet treat it as missing -- the
     * ratio-bots:reconcile schedule runs every few minutes, so a bot that
     * just opened a position minutes ago legitimately has no stale data to
     * report, and treating that as danger would force-exit healthy new
     * positions on a timer, not on real risk.
     */
    private const FRESH_WINDOW_MINUTES = 10;

    /**
     * Beyond this, synced data is old enough that the reconcile job itself
     * may be stuck (this repo has previously had a daemon go quiet for
     * hours undetected) -- fail toward caution by blocking new entries, but
     * deliberately NOT by force-exiting a real position on data staleness
     * alone. A force-exit is an irreversible real trade; missing monitoring
     * data is a reason to stop adding risk and alert loudly, not a
     * substitute for an actual confirmed danger reading.
     */
    private const STALE_WINDOW_MINUTES = 30;

    public function assess(RatioBotConfig $config): array
    {
        if (! $config->liquidationGuardEnabled()) {
            return $this->result(false, null, 'safe', 'none', 'liquidation guard is not enabled for this bot', null);
        }

        $symbol = Symbol::query()->where('symbol', $config->normalizedSymbol())->first();
        $position = $symbol
            ? Position::query()
                ->where('workspace_id', (string) $config->workspace_id)
                ->where('symbol_id', $symbol->id)
                ->where('market_type', 'futures')
                ->first()
            : null;

        $qty = $position ? abs((float) ($position->qty ?? 0)) : 0.0;
        if ($position === null || $qty <= 0) {
            return $this->result(true, null, 'safe', 'none', 'no open futures position for this symbol', null);
        }

        $syncedAt = $position->mark_price_synced_at;
        $ageMinutes = $syncedAt instanceof Carbon ? $syncedAt->diffInMinutes(now()) : null;
        $buffer = $position->liquidation_buffer_pct !== null ? (float) $position->liquidation_buffer_pct : null;

        if ($buffer === null || $ageMinutes === null || $ageMinutes > self::STALE_WINDOW_MINUTES) {
            return $this->result(
                true,
                $buffer,
                'critical',
                'block_new_entries',
                'liquidation safety data is missing or too stale to trust (reconcile may be delayed) -- blocking new entries until fresh data arrives',
                $position,
            );
        }

        $watch = $config->liquidationWatchBufferPct();
        $critical = $config->liquidationCriticalBufferPct();

        if ($buffer <= $critical) {
            return $this->result(
                true,
                $buffer,
                'critical',
                'force_exit',
                sprintf('liquidation buffer %.2f%% is at or below the critical threshold %.2f%%', $buffer, $critical),
                $position,
            );
        }

        if ($buffer <= $watch) {
            return $this->result(
                true,
                $buffer,
                'watch',
                'block_new_entries',
                sprintf('liquidation buffer %.2f%% is at or below the watch threshold %.2f%%', $buffer, $watch),
                $position,
            );
        }

        return $this->result(
            true,
            $buffer,
            'safe',
            'none',
            sprintf('liquidation buffer %.2f%% is above the watch threshold %.2f%%', $buffer, $watch),
            $position,
        );
    }

    /**
     * Detection-only sibling check (Part C.4's narrow, always-on scope --
     * full distributed locking of a shared force-exit is intentionally not
     * built in this pass). An operator must never be blind to two bots
     * looking at the same exchange position.
     */
    public function sharedPositionSiblings(RatioBotConfig $config): array
    {
        return RatioBotConfig::query()
            ->where('id', '!=', $config->id)
            ->where('workspace_id', (string) $config->workspace_id)
            ->where('market_type', 'futures')
            ->where('enabled', true)
            ->get(['id', 'name', 'symbol'])
            ->filter(fn (RatioBotConfig $sibling) => $sibling->normalizedSymbol() === $config->normalizedSymbol())
            ->map(fn (RatioBotConfig $sibling) => ['id' => (string) $sibling->id, 'name' => $sibling->name])
            ->values()
            ->all();
    }

    private function result(
        bool $applicable,
        ?float $bufferPct,
        string $dangerLevel,
        string $action,
        string $reason,
        ?Position $position,
    ): array {
        return [
            'applicable' => $applicable,
            'buffer_pct' => $bufferPct !== null ? round($bufferPct, 4) : null,
            'danger_level' => $dangerLevel,
            'action' => $action,
            'reason' => $reason,
            'mark_price' => $position?->last_mark_price !== null ? (float) $position->last_mark_price : null,
            'liquidation_price' => $position?->liquidation_price !== null ? (float) $position->liquidation_price : null,
            'position_qty' => $position?->qty !== null ? (float) $position->qty : null,
            'position_side' => $position !== null ? ((float) $position->qty < 0 ? 'short' : 'long') : null,
            'position_synced_at' => optional($position?->mark_price_synced_at)->toIso8601String(),
        ];
    }
}
