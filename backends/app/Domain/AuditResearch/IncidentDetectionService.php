<?php

namespace App\Domain\AuditResearch;

use App\Models\AuditIncident;
use App\Models\RatioBotConfig;
use App\Domain\Ops\OperationalBreakerService;
use Illuminate\Support\Carbon;

/**
 * Consumes OperationalBreakerService's existing signals (stale feed,
 * provider outage, stream heartbeat) — reuses them as the real health input,
 * never a parallel heartbeat mechanism. Applies simple debounce/aggregation
 * so a transient blip doesn't spam duplicate incident rows (spec §14/§16):
 * a condition that clears within SELF_RECOVER_WINDOW_SECONDS of first
 * detection self-recovers with no lingering open incident; the same
 * condition recurring ESCALATION_OCCURRENCE_THRESHOLD+ times within
 * DEDUPE_WINDOW_MINUTES escalates an EXISTING open incident row rather than
 * creating a new one.
 */
class IncidentDetectionService
{
    public const SELF_RECOVER_WINDOW_SECONDS = 120;
    public const ESCALATION_OCCURRENCE_THRESHOLD = 3;
    public const PERSISTENT_OCCURRENCE_THRESHOLD = 6;
    public const DEDUPE_WINDOW_MINUTES = 60;

    public function __construct(
        private readonly OperationalBreakerService $breakers,
    ) {
    }

    /**
     * Reads the real stale-feed breaker for this bot's symbol and
     * evaluates/updates the incident ledger. Safe to call on every
     * evaluation tick — idempotent by construction.
     */
    public function detectStaleFeedForBot(RatioBotConfig $config): ?AuditIncident
    {
        $symbol = $config->normalizedSymbol();
        $state = $this->breakers->staleFeedState((string) $config->workspace_id, $symbol);
        $active = (bool) ($state['active'] ?? false);

        return $this->evaluateSignal(
            workspaceId: (string) $config->workspace_id,
            botType: 'ratio_bot',
            botInstanceId: (string) $config->id,
            symbol: $symbol,
            rootCauseCategory: RootCauseCategory::DATA_STALE,
            title: "Stale market feed for {$symbol}",
            severity: 'medium',
            conditionActive: $active,
            evidence: ['breaker_state' => $state],
        );
    }

    /**
     * Generic entry point — any other detector (provider outage, stream
     * heartbeat gap, repeated `audit_events` of category health/system with
     * severity >= medium) can reuse this same idempotent aggregation logic.
     */
    public function evaluateSignal(
        string $workspaceId,
        string $botType,
        ?string $botInstanceId,
        ?string $symbol,
        string $rootCauseCategory,
        string $title,
        string $severity,
        bool $conditionActive,
        array $evidence = [],
    ): ?AuditIncident {
        $existing = AuditIncident::query()
            ->where('workspace_id', $workspaceId)
            ->where('bot_type', $botType)
            ->where('bot_instance_id', $botInstanceId)
            ->where('symbol', $symbol)
            ->where('root_cause_category', $rootCauseCategory)
            ->whereIn('status', AuditIncident::OPEN_STATUSES)
            ->latest('last_detected_at')
            ->first();

        $now = now();

        if ($conditionActive) {
            if ($existing === null) {
                return AuditIncident::create([
                    'workspace_id' => $workspaceId,
                    'bot_type' => $botType,
                    'bot_instance_id' => $botInstanceId,
                    'symbol' => $symbol,
                    'title' => $title,
                    'root_cause_category' => $rootCauseCategory,
                    'severity' => $severity,
                    'status' => AuditIncident::STATUS_DETECTED,
                    'first_detected_at' => $now,
                    'last_detected_at' => $now,
                    'occurrence_count' => 1,
                    'self_recovered' => false,
                    'evidence' => $evidence,
                ]);
            }

            // Outside the dedupe window: treat as a fresh incident lineage
            // rather than resurrecting an old, effectively-unrelated one.
            $withinDedupeWindow = $existing->last_detected_at !== null
                && $existing->last_detected_at->diffInMinutes($now) <= self::DEDUPE_WINDOW_MINUTES;

            if (! $withinDedupeWindow) {
                $existing->update([
                    'occurrence_count' => 1,
                    'first_detected_at' => $now,
                    'last_detected_at' => $now,
                    'status' => AuditIncident::STATUS_DETECTED,
                    'evidence' => $evidence,
                ]);

                return $existing->fresh();
            }

            $occurrenceCount = (int) $existing->occurrence_count + 1;
            $status = AuditIncident::STATUS_ACTIVE;
            if ($occurrenceCount >= self::PERSISTENT_OCCURRENCE_THRESHOLD) {
                $status = AuditIncident::STATUS_PERSISTENT;
            } elseif ($occurrenceCount >= self::ESCALATION_OCCURRENCE_THRESHOLD) {
                $status = AuditIncident::STATUS_RECURRING;
            }

            $existing->update([
                'occurrence_count' => $occurrenceCount,
                'last_detected_at' => $now,
                'status' => $status,
                'evidence' => $evidence,
            ]);

            return $existing->fresh();
        }

        // Condition cleared.
        if ($existing === null) {
            return null; // nothing open to close — no-op, not a new row.
        }

        $selfRecovered = (int) $existing->occurrence_count <= 1
            && $existing->first_detected_at !== null
            && $existing->first_detected_at->diffInSeconds($now) <= self::SELF_RECOVER_WINDOW_SECONDS;

        $existing->update([
            'status' => AuditIncident::STATUS_RECOVERED,
            'recovered_at' => $now,
            'self_recovered' => $selfRecovered,
        ]);

        return $existing->fresh();
    }
}
