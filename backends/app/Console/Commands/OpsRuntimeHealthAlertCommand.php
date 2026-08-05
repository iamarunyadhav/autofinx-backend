<?php

namespace App\Console\Commands;

use App\Domain\Ops\TradingRuntimeHealthService;
use App\Models\Notification;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Edge-triggered alert on the Binance futures live-feed health check
 * (TradingRuntimeHealthService::snapshot()['future_trade']['live_feed']).
 *
 * This is the exact 3-second-freshness gate that silently blocks Robot Trade
 * and Future Trade live entries when the `future-trade:live-feed` daemon
 * (autofinx-trading:live-feed supervisor program) goes down. Without this
 * command, a stale feed can go unnoticed for hours (as observed) — this
 * fires ONLY on a green->red transition (not every red minute) to avoid
 * alert-storms, then again on red->green recovery.
 */
class OpsRuntimeHealthAlertCommand extends Command
{
    protected $signature = 'ops:runtime-health-alert';

    protected $description = 'Alert (in-app + optional webhook) on futures live-feed green<->red transitions.';

    public function handle(TradingRuntimeHealthService $health): int
    {
        $workspaces = Workspace::query()->select('id', 'name')->get();

        foreach ($workspaces as $workspace) {
            $this->checkWorkspace($health, (string) $workspace->id, (string) ($workspace->name ?? $workspace->id));
        }

        return self::SUCCESS;
    }

    private function checkWorkspace(TradingRuntimeHealthService $health, string $workspaceId, string $workspaceName): void
    {
        $snapshot = $health->snapshot($workspaceId);
        $state = (string) ($snapshot['future_trade']['live_feed'] ?? 'unknown');
        $detail = (array) ($snapshot['future_trade']['live_feed_detail'] ?? []);

        $cacheKey = "ops:runtime-health:live-feed-state:{$workspaceId}";
        $lastState = Cache::get($cacheKey);

        // Edge-triggered: only act on an actual transition, never on every red tick.
        if ($lastState === $state) {
            return;
        }
        Cache::put($cacheKey, $state, now()->addDay());

        // First observation ever (no prior cached state) — record baseline, don't alert.
        if ($lastState === null) {
            return;
        }

        $becameUnhealthy = $lastState !== 'red' && $state === 'red';
        $recovered = $lastState === 'red' && $state !== 'red';
        if (! $becameUnhealthy && ! $recovered) {
            return;
        }

        $title = $becameUnhealthy
            ? "Futures live feed DOWN — {$workspaceName}"
            : "Futures live feed recovered — {$workspaceName}";
        $body = sprintf(
            'live_feed state: %s -> %s. reason=%s truth_source=%s age_seconds=%s',
            $lastState,
            $state,
            (string) ($detail['reason'] ?? 'n/a'),
            (string) ($detail['truth_source'] ?? 'n/a'),
            (string) ($detail['age_seconds'] ?? 'n/a'),
        );

        Notification::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'type' => 'ops_runtime_health_alert',
            'content' => [
                'title' => $title,
                'body' => $body,
                'severity' => $becameUnhealthy ? 'critical' : 'info',
                'live_feed_detail' => $detail,
            ],
        ]);

        $this->line("[{$workspaceId}] {$title}");

        $webhookUrl = (string) config('services.ops_alert.webhook_url', '');
        if ($webhookUrl !== '') {
            try {
                Http::timeout(5)->post($webhookUrl, ['text' => "{$title}\n{$body}"]);
            } catch (\Throwable $e) {
                $this->warn("Ops alert webhook post failed: {$e->getMessage()}");
            }
        }
    }
}
