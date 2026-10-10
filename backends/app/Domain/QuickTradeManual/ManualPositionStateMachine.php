<?php

namespace App\Domain\QuickTradeManual;

use App\Domain\AuditResearch\AuditEventRecorder;
use App\Models\AuditEvent;
use App\Models\QuickTradeManualEvent;
use App\Models\QuickTradeManualPosition as P;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The only writer of `status`. Every transition is a compare-and-set
 * (`UPDATE ... WHERE id = ? AND status IN (allowed sources)`), so when two workers race
 * (user close vs reconciler vs SL fill) exactly one wins and the loser gets `false` and
 * must not act. Each successful transition appends one row to quick_trade_manual_events in
 * the same DB transaction. Terminal states clear `active_key`, freeing the symbol slot.
 */
class ManualPositionStateMachine
{
    /** @var array<string, list<string>> target => allowed source statuses */
    public const ALLOWED_FROM = [
        P::ENTRY_FILLED => [P::ENTRY_PENDING, P::EXCHANGE_STATE_UNKNOWN],
        P::PARTIALLY_FILLED => [P::ENTRY_PENDING, P::EXCHANGE_STATE_UNKNOWN],
        P::ENTRY_FAILED => [P::ENTRY_PENDING, P::EXCHANGE_STATE_UNKNOWN],
        P::PROTECTION_PENDING => [P::ENTRY_FILLED, P::PARTIALLY_FILLED, P::EXCHANGE_STATE_UNKNOWN],
        P::OPEN_PROTECTED => [P::PROTECTION_PENDING, P::CLOSE_FAILED, P::EXCHANGE_STATE_UNKNOWN],
        P::PROTECTION_FAILED => [P::PROTECTION_PENDING, P::OPEN_PROTECTED, P::EXCHANGE_STATE_UNKNOWN],
        P::EMERGENCY_EXIT => [P::PROTECTION_FAILED, P::CLOSE_FAILED, P::EXCHANGE_STATE_UNKNOWN],
        P::CLOSING => [P::OPEN_PROTECTED, P::CLOSE_FAILED],
        P::CLOSE_FAILED => [P::CLOSING, P::EMERGENCY_EXIT, P::EXCHANGE_STATE_UNKNOWN],
        P::RECONCILIATION => [P::OPEN_PROTECTED, P::CLOSING, P::EMERGENCY_EXIT, P::CLOSE_FAILED, P::EXCHANGE_STATE_UNKNOWN],
        P::CLOSED => [P::RECONCILIATION],
        P::LIQUIDATED => [P::RECONCILIATION],
        P::EXCHANGE_STATE_UNKNOWN => [
            P::ENTRY_PENDING, P::ENTRY_FILLED, P::PARTIALLY_FILLED, P::PROTECTION_PENDING,
            P::OPEN_PROTECTED, P::CLOSING, P::CLOSE_FAILED, P::EMERGENCY_EXIT, P::RECONCILIATION,
        ],
    ];

    /**
     * @param  array<string, mixed>  $updates  extra columns to set atomically with the status (scalars/null/Carbon only; json via jsonColumn())
     * @return bool true = this caller performed the transition; false = lost the race / not allowed
     */
    public function transition(P $p, string $to, string $source, array $updates = [], array $payload = [], string $type = '', string $severity = 'info'): bool
    {
        $allowed = self::ALLOWED_FROM[$to] ?? [];
        if ($allowed === []) {
            return false;
        }

        $transitioned = DB::transaction(function () use ($p, $to, $allowed, $source, $updates, $payload, $type, $severity): bool {
            $set = array_merge($updates, ['status' => $to, 'updated_at' => now()]);
            if (in_array($to, P::TERMINAL, true)) {
                $set['active_key'] = null;
            }

            $current = P::query()->whereKey($p->id)->value('status');
            $n = P::query()->whereKey($p->id)->whereIn('status', $allowed)->update($set);
            if ($n !== 1) {
                return false;
            }

            $this->writeEvent($p, $type !== '' ? $type : 'status_'.strtolower($to), $source, (string) $current, $to, $payload, $severity);

            return true;
        });

        $p->refresh();

        if ($transitioned && $severity === 'critical') {
            $this->raiseCritical($p, $type !== '' ? $type : 'status_'.strtolower($to), $payload);
        }

        return $transitioned;
    }

    /** Append an event without changing status (observations, warnings, retries). */
    public function note(P $p, string $type, string $source, array $payload = [], string $severity = 'info'): void
    {
        $this->writeEvent($p, $type, $source, (string) $p->status, null, $payload, $severity);
        if ($severity === 'critical') {
            $this->raiseCritical($p, $type, $payload);
        }
    }

    /**
     * Non-status column update guarded by "still one of these statuses".
     * @param  list<string>  $onlyIn
     */
    public function patch(P $p, array $updates, array $onlyIn = []): bool
    {
        $q = P::query()->whereKey($p->id);
        if ($onlyIn !== []) {
            $q->whereIn('status', $onlyIn);
        }
        $n = $q->update(array_merge($updates, ['updated_at' => now()]));
        $p->refresh();

        return $n === 1;
    }

    public static function jsonColumn(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';
    }

    private function writeEvent(P $p, string $type, string $source, ?string $from, ?string $to, array $payload, string $severity): void
    {
        QuickTradeManualEvent::create([
            'id' => (string) Str::uuid(),
            'position_id' => $p->id,
            'workspace_id' => $p->workspace_id,
            'type' => mb_substr($type, 0, 48),
            'from_status' => $from !== '' ? $from : null,
            'to_status' => $to,
            'source' => $source,
            'severity' => $severity,
            'payload' => $payload === [] ? null : $payload,
        ]);
    }

    /** No in-app push channel exists in this repo: log, audit event, and a flag the UI renders. */
    private function raiseCritical(P $p, string $type, array $payload): void
    {
        try {
            P::query()->whereKey($p->id)->update(['critical_alert' => true]);
            $p->refresh();
        } catch (\Throwable) {
        }

        Log::critical('quick_trade_manual_critical', [
            'position_id' => $p->id,
            'workspace_id' => $p->workspace_id,
            'symbol' => $p->symbol,
            'status' => $p->status,
            'type' => $type,
            'payload' => $payload,
        ]);

        AuditEventRecorder::record(
            eventType: 'quick_trade_manual_'.$type,
            category: AuditEvent::CATEGORY_INCIDENT,
            context: [
                'bot_type' => 'quick_trade',
                'bot_instance_id' => null,
                'environment' => $p->environment,
                'symbol' => $p->symbol,
                'metadata' => array_merge(['position_id' => $p->id, 'status' => $p->status], $payload),
            ],
            correlationId: $p->id,
            severity: AuditEvent::SEVERITY_CRITICAL,
        );
    }
}
