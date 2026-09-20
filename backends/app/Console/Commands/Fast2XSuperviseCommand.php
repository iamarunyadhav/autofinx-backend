<?php

namespace App\Console\Commands;

use App\Domain\Fast2X\Fast2XDaemonManager;
use App\Models\Fast2XState;
use Illuminate\Console\Command;

/**
 * Restart-on-crash / restart-on-reboot supervision for Fast2X's per-workspace
 * `fast2x:run {workspace_id}` daemon.
 *
 * Fast2X differs from Ratio Bot's `market:ws-consume` and Future Trade's live
 * feed (both ONE global daemon covering every workspace/symbol): a separate
 * `fast2x:run` process is meant to exist per workspace that has Fast2X
 * running, and no static Supervisor `.conf` can enumerate an arbitrary,
 * dynamically-changing set of workspace IDs. Instead this mirrors
 * RuntimeRepairCommand's self-healing style — a command scheduled every
 * minute (routes/console.php) that enumerates the actually-active set from
 * the database and repairs whatever is unhealthy, rather than a fixed
 * process list.
 *
 * "Active" here means Fast2XState.status === 'Running' (the user pressed
 * Start on the Fast2X panel) — Stopped/Paused workspaces intentionally have
 * no daemon and are left alone.
 *
 * Liveness is judged by Fast2XState.last_heartbeat_at, written every loop
 * iteration by `fast2x:run` itself (see Fast2XRunCommand) independent of
 * whether a tick was actually fetched — this reflects the OS process still
 * spinning, not just "a queue job exists" or "the exchange feed is fresh"
 * (last_price_update_at can go stale during a real Binance-side outage while
 * the process is perfectly healthy; last_heartbeat_at cannot).
 */
class Fast2XSuperviseCommand extends Command
{
    protected $signature = 'fast2x:supervise {--stale-seconds= : Heartbeat age in seconds before a daemon is considered dead}';

    protected $description = 'Restart any Fast2X workspace daemon (fast2x:run) whose heartbeat has gone stale or was never started.';

    public function handle(Fast2XDaemonManager $daemons): int
    {
        $staleSeconds = max(15, (int) ($this->option('stale-seconds') ?: config('fast2x.supervisor_stale_seconds', 90)));

        $states = Fast2XState::query()
            ->where('status', 'Running')
            ->with('config')
            ->get()
            ->filter(fn (Fast2XState $state) => $state->config !== null);

        if ($states->isEmpty()) {
            $this->line('No Fast2X workspaces are marked Running; nothing to supervise.');

            return self::SUCCESS;
        }

        $restarted = 0;
        $failed = 0;

        foreach ($states as $state) {
            $workspaceId = (string) $state->config->workspace_id;
            // diffInSeconds() with no argument compares against now() and is
            // absolute by default; calling it the other way round
            // (now()->diffInSeconds($past)) returns a *signed* (negative)
            // value in this Carbon version, which silently made every
            // heartbeat look "fresh" regardless of age -- caught by this
            // command's own test suite.
            $ageSeconds = $state->last_heartbeat_at ? $state->last_heartbeat_at->diffInSeconds() : null;
            $isStale = $ageSeconds === null || $ageSeconds >= $staleSeconds;

            if (! $isStale) {
                continue;
            }

            $this->warn(sprintf(
                'Fast2X daemon for workspace %s looks dead (heartbeat age: %s). Restarting fast2x:run.',
                $workspaceId,
                $ageSeconds === null ? 'never recorded' : $ageSeconds.'s'
            ));

            if ($daemons->isRunning($workspaceId)) {
                // A tracked PID still exists but the heartbeat stopped
                // advancing -- hung, not crashed. Kill it first so we never
                // end up with two processes racing to place orders for the
                // same workspace.
                $daemons->terminate($workspaceId);
            }

            $meta = $daemons->spawn($workspaceId);
            if ($meta === null) {
                $failed++;
                $this->error("Could not spawn fast2x:run for workspace {$workspaceId}: this host cannot spawn background processes (shell_exec/exec/proc_open unavailable). A Supervisor-managed process or manual restart is required.");

                continue;
            }

            $restarted++;
            $this->info("Restarted fast2x:run for workspace {$workspaceId} (pid {$meta['pid']}).");
        }

        $this->info("Fast2X supervision complete: {$restarted} restarted, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
