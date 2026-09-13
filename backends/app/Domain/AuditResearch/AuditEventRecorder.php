<?php

namespace App\Domain\AuditResearch;

use App\Models\AuditEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The lightweight, synchronous-safe capture point called from real Ratio
 * Bot code. Deliberately a plain DB insert wrapped in a try/catch that NEVER
 * throws back into the trading path — log-and-continue on failure, exactly
 * the same "audit failure must not corrupt trading" principle already
 * established for other safety nets in this codebase (e.g. RatioBotAlertService
 * notification failures). Instrumentation must not become a new reason for
 * a missed trade.
 */
final class AuditEventRecorder
{
    public static function record(
        string $eventType,
        string $category,
        array $context = [],
        ?string $correlationId = null,
        string $severity = AuditEvent::SEVERITY_INFO,
    ): ?AuditEvent {
        // Checked first, before any DB work, so the flag is a true no-op.
        if (! (bool) config('audit_research.enabled', true)) {
            return null;
        }

        try {
            return AuditEvent::create([
                'audit_session_id' => $context['audit_session_id'] ?? null,
                'correlation_id' => $correlationId,
                'bot_type' => $context['bot_type'] ?? 'ratio_bot',
                'bot_instance_id' => $context['bot_instance_id'] ?? null,
                'environment' => $context['environment'] ?? null,
                'symbol' => $context['symbol'] ?? null,
                'event_type' => $eventType,
                'event_category' => $category,
                'severity' => $severity,
                'occurred_at' => $context['occurred_at'] ?? now(),
                'received_at' => now(),
                'market_price' => $context['market_price'] ?? null,
                'latency_ms' => $context['latency_ms'] ?? null,
                'metadata' => SecretFieldScrubber::scrub((array) ($context['metadata'] ?? [])),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('[AuditResearch] Failed to record audit event (non-fatal, trading path unaffected)', [
                'event_type' => $eventType,
                'category' => $category,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
