<?php

namespace App\Domain\RatioBot;

use App\Domain\Ops\OperationalBreakerService;
use App\Domain\Ops\OperationalHealthService;
use App\Models\RatioBotConfig;
use App\Models\RatioBotEvent;

/**
 * Read-only, per-bot "why is this stuck/stale/confusing" diagnostics panel
 * (2026-09-06). Every check is scoped to *this bot's own symbol* — never a
 * workspace-wide-only read — because a no-symbol-scoped breaker read was
 * exactly the class of bug this panel exists to help operators catch and
 * self-heal without SSH access. Composes existing services; does not
 * duplicate their logic (see RatioBotFixActionService for the matching,
 * narrowly-whitelisted remediation half).
 */
class RatioBotDiagnosticsService
{
    public function __construct(
        private readonly RatioBotRuntimeQueryService $runtime,
        private readonly OperationalBreakerService $breakers,
        private readonly OperationalHealthService $health,
    ) {
    }

    public function diagnose(RatioBotConfig $config): array
    {
        // effectiveFeedStatus() itself already clears the symbol breaker when
        // the snapshot is genuinely fresh (see RatioBotRuntimeQueryService) —
        // calling it here means a "zombie breaker" can self-heal just from
        // opening this panel, not only from clicking Fix.
        $feedStatus = $this->runtime->effectiveFeedStatus($config);

        $checks = [
            $this->startupChecklistCheck($config),
            $this->marketFeedCheck($feedStatus),
            $this->symbolBreakerCheck($config, $feedStatus),
            $this->recentEventsCheck($config),
            $this->daemonHealthCheck($config),
            $this->siblingBotsCheck($config),
        ];

        $overall = 'pass';
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $overall = 'fail';
                break;
            }
            if ($check['status'] === 'warn') {
                $overall = 'warn';
            }
        }

        return [
            'checks' => $checks,
            'overall' => $overall,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    private function startupChecklistCheck(RatioBotConfig $config): array
    {
        $checklist = $this->runtime->startupChecklist($config);
        $items = collect($checklist['items'] ?? []);
        $failing = $items->where('status', 'fail')->pluck('label')->all();
        $warning = $items->where('status', 'warn')->pluck('label')->all();

        $status = $failing !== [] ? 'fail' : ($warning !== [] ? 'warn' : 'pass');
        $detail = match (true) {
            $failing !== [] => 'Failing: ' . implode(', ', $failing) . '.',
            $warning !== [] => 'Warnings: ' . implode(', ', $warning) . '.',
            default => 'All startup checklist items pass.',
        };

        return $this->result('startup_checklist', 'Startup readiness checklist', $status, $detail, $checklist);
    }

    private function marketFeedCheck(array $feedStatus): array
    {
        $fresh = (bool) ($feedStatus['fresh'] ?? false);
        $ageSeconds = $feedStatus['age_seconds'] ?? null;
        $maxAge = $feedStatus['max_age_seconds'] ?? null;

        $detail = $fresh
            ? sprintf(
                'Feed is fresh (%s%s, limit %ss).',
                $feedStatus['status'] ?? 'FRESH',
                $ageSeconds !== null ? ", {$ageSeconds}s old" : '',
                $maxAge ?? '?'
            )
            : sprintf(
                'Feed is not fresh: %s (%s).',
                $feedStatus['status'] ?? 'STALE_FEED',
                $feedStatus['reason'] ?? 'unknown'
            );

        // Not fixable from here — see spec section 6: the actual Binance
        // feed/WS daemon can't be fixed by an in-app button.
        return $this->result('market_feed', 'Market feed freshness', $fresh ? 'pass' : 'fail', $detail, $feedStatus);
    }

    private function symbolBreakerCheck(RatioBotConfig $config, array $feedStatus): array
    {
        $symbol = $config->normalizedSymbol();
        $workspaceId = (string) $config->workspace_id;
        $active = $this->breakers->isStaleFeedBreakerActive($workspaceId, $symbol, $config->marketType());
        $fresh = (bool) ($feedStatus['fresh'] ?? false);

        if (! $active) {
            return $this->result(
                'symbol_breaker',
                'Stale-feed breaker (this symbol)',
                'pass',
                "No stale-feed breaker is active for {$symbol}.",
                ['active' => false, 'symbol' => $symbol]
            );
        }

        if ($fresh) {
            return $this->result(
                'symbol_breaker',
                'Stale-feed breaker (this symbol)',
                'fail',
                "Breaker is active for {$symbol} but the feed is actually fresh right now — this looks like a stuck (\"zombie\") breaker.",
                ['active' => true, 'feed_fresh' => true, 'symbol' => $symbol],
                fixable: true,
                fixKey: 'clear_symbol_stale_breaker',
            );
        }

        return $this->result(
            'symbol_breaker',
            'Stale-feed breaker (this symbol)',
            'fail',
            "Breaker is active for {$symbol} and the feed genuinely is not fresh — clearing it now would just have the detector re-trip it. Fix the underlying feed first (see Market feed freshness above).",
            ['active' => true, 'feed_fresh' => false, 'symbol' => $symbol],
        );
    }

    private function recentEventsCheck(RatioBotConfig $config): array
    {
        $events = RatioBotEvent::query()
            ->where('ratio_bot_config_id', $config->id)
            ->orderByDesc('occurred_at')
            ->limit(5)
            ->get(['event_type', 'detail', 'context', 'occurred_at']);

        if ($events->isEmpty()) {
            return $this->result('recent_events', 'Recent bot events', 'pass', 'No events recorded yet.', []);
        }

        $lines = $events->map(static function (RatioBotEvent $event): string {
            $reason = data_get($event->context, 'reason');
            $suffix = $reason ? " — {$reason}" : '';
            $timestamp = $event->occurred_at?->toIso8601String() ?? 'unknown time';

            return "{$timestamp} {$event->event_type}{$suffix}";
        })->all();

        return $this->result('recent_events', 'Recent bot events', 'pass', implode("\n", $lines), $events->toArray());
    }

    private function daemonHealthCheck(RatioBotConfig $config): array
    {
        $summary = $this->health->summary((string) $config->workspace_id);
        $blockers = $summary['blockers'] ?? [];
        $warnings = $summary['warnings'] ?? [];

        $status = $blockers !== [] ? 'fail' : ($warnings !== [] ? 'warn' : 'pass');
        $detail = match (true) {
            $blockers !== [] => 'Blocked: ' . implode(' ', $blockers)
                . ' If this persists, check on the server: supervisorctl status autofinx-ratio-feed autofinx-queue-ratio',
            $warnings !== [] => 'Warning: ' . implode(' ', $warnings),
            default => 'Queue, scheduler, and stream health all look normal.',
        };

        return $this->result('daemon_health', 'Background daemon / queue health', $status, $detail, [
            'workspace_state' => $summary['state'] ?? null,
            'checks' => $summary['checks'] ?? null,
            'counts' => $summary['counts'] ?? null,
        ]);
    }

    private function siblingBotsCheck(RatioBotConfig $config): array
    {
        $symbol = $config->normalizedSymbol();
        $siblings = RatioBotConfig::query()
            ->where('id', '!=', $config->id)
            ->where('enabled', true)
            ->get(['id', 'name', 'symbol', 'workspace_id', 'execution_mode'])
            ->filter(fn (RatioBotConfig $sibling) => $sibling->normalizedSymbol() === $symbol)
            ->values();

        if ($siblings->isEmpty()) {
            return $this->result(
                'sibling_bots_same_symbol',
                'Other bots on this symbol',
                'pass',
                "No other enabled bot targets {$symbol}.",
                []
            );
        }

        $names = $siblings->map(fn (RatioBotConfig $sibling) => "{$sibling->name} ({$sibling->execution_mode})")->implode(', ');

        return $this->result(
            'sibling_bots_same_symbol',
            'Other bots on this symbol',
            'pass',
            $siblings->count() . " other enabled bot(s) also target {$symbol}: {$names}. A confusing symptom may belong to one of these, not this bot.",
            $siblings->values()->toArray(),
        );
    }

    private function result(
        string $key,
        string $label,
        string $status,
        string $detail,
        mixed $raw = null,
        bool $fixable = false,
        ?string $fixKey = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
            'raw' => $raw,
            'fixable' => $fixable,
            'fix_key' => $fixable ? $fixKey : null,
        ];
    }
}
