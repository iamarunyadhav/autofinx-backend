<?php

namespace App\Domain\AuditResearch;

use App\Models\AuditDecisionSnapshot;
use App\Models\RatioBotDecision;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Builds the passed/failed rules array + expected-vs-actual + root-cause
 * hint for one decision. The underlying reasoning (guard names, risk-block
 * codes, trigger types) already exists today as local variables/branches in
 * RatioBotDecisionService/RunRatioBotEvaluationJob — this class turns that
 * existing flat `reason` string + `metadata` array into a structured
 * passed/failed rules record. It never re-derives the decision itself.
 *
 * Never throws back into the trading path — same log-and-continue
 * discipline as AuditEventRecorder.
 */
final class RuleEvaluationRecorder
{
    /**
     * Main path — called once a RatioBotDecision row already exists (BUY,
     * SELL, HOLD, BLOCK, PAUSE, COOLDOWN, LONG/SHORT/EXIT_*). Reuses the
     * decision's own correlation_id, never invents a second one.
     */
    public static function recordForDecision(RatioBotDecision $decision, ?string $auditEventId = null): ?AuditDecisionSnapshot
    {
        if (! (bool) config('audit_research.enabled', true)) {
            return null;
        }

        try {
            $metadata = (array) ($decision->metadata ?? []);
            $classification = self::classifyDecision($decision, $metadata);

            return AuditDecisionSnapshot::create([
                'audit_event_id' => $auditEventId,
                'ratio_bot_decision_id' => $decision->id,
                'correlation_id' => $decision->correlation_id,
                'passed_rules' => $classification['passed'],
                'failed_rules' => $classification['failed'],
                'final_decision' => $decision->decision,
                'final_reason' => $decision->reason,
                'expected_behavior' => $classification['expected'],
                'actual_behavior' => $classification['actual'],
                'diagnosis_category' => $classification['category'],
                'diagnosis_confidence' => $classification['confidence'],
            ]);
        } catch (Throwable $e) {
            Log::warning('[AuditResearch] Failed to record rule-evaluation snapshot (non-fatal)', [
                'decision_id' => $decision->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Secondary path — RunRatioBotEvaluationJob::resolveAutoStartDirection()
     * doesn't create a RatioBotDecision (it's a pre-anchor posture choice,
     * logged instead as a RatioBotEvent of type auto_start_direction_selected
     * / auto_start_direction_waiting). Same shape, no ratio_bot_decision_id.
     */
    public static function recordForAutoStartContext(array $context, ?string $auditEventId = null, ?string $correlationId = null): ?AuditDecisionSnapshot
    {
        if (! (bool) config('audit_research.enabled', true)) {
            return null;
        }

        try {
            $classification = self::classifyAutoStartContext($context);

            return AuditDecisionSnapshot::create([
                'audit_event_id' => $auditEventId,
                'ratio_bot_decision_id' => null,
                'correlation_id' => $correlationId,
                'passed_rules' => $classification['passed'],
                'failed_rules' => $classification['failed'],
                'final_decision' => $context['direction'] ?? null,
                'final_reason' => $context['reason'] ?? null,
                'expected_behavior' => $classification['expected'],
                'actual_behavior' => $classification['actual'],
                'diagnosis_category' => $classification['category'],
                'diagnosis_confidence' => $classification['confidence'],
            ]);
        } catch (Throwable $e) {
            Log::warning('[AuditResearch] Failed to record auto-start rule-evaluation snapshot (non-fatal)', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Fully generic entry point for engines other than Ratio Bot (Quick
     * Trade, Robot Trade, Future Trade) that already know their own
     * passed/failed gate outcomes as plain booleans/branches in their own
     * code -- this method only persists an already-classified shape into
     * AuditDecisionSnapshot, it never re-derives a classification the way
     * classifyDecision()/classifyAutoStartContext() do for Ratio Bot's flat
     * reason strings. Same log-and-continue discipline as the rest of this
     * class, and the same config flag gate.
     */
    public static function recordGeneric(
        array $passedRules,
        array $failedRules,
        ?string $finalDecision,
        ?string $finalReason,
        string $diagnosisCategory,
        string $diagnosisConfidence,
        ?string $expectedBehavior = null,
        ?string $actualBehavior = null,
        ?string $auditEventId = null,
        ?string $correlationId = null,
    ): ?AuditDecisionSnapshot {
        if (! (bool) config('audit_research.enabled', true)) {
            return null;
        }

        try {
            return AuditDecisionSnapshot::create([
                'audit_event_id' => $auditEventId,
                'ratio_bot_decision_id' => null,
                'correlation_id' => $correlationId,
                'passed_rules' => $passedRules,
                'failed_rules' => $failedRules,
                'final_decision' => $finalDecision,
                'final_reason' => $finalReason,
                'expected_behavior' => $expectedBehavior,
                'actual_behavior' => $actualBehavior,
                'diagnosis_category' => $diagnosisCategory,
                'diagnosis_confidence' => $diagnosisConfidence,
            ]);
        } catch (Throwable $e) {
            Log::warning('[AuditResearch] Failed to record generic rule-evaluation snapshot (non-fatal)', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{passed: array, failed: array, category: string, confidence: string, expected: ?string, actual: ?string}
     */
    private static function classifyDecision(RatioBotDecision $decision, array $metadata): array
    {
        $reason = (string) ($decision->reason ?? '');
        $isTrade = $decision->isTrade();

        if ($isTrade) {
            return [
                'passed' => [
                    ['rule' => 'threshold_ratio_check', 'detail' => $reason],
                ],
                'failed' => [],
                'category' => RootCauseCategory::CORRECT_BEHAVIOR,
                'confidence' => 'high',
                'expected' => 'Execute ' . $decision->decision . ' once the configured ratio/threshold rule is satisfied.',
                'actual' => 'Executed ' . $decision->decision . ' — rule satisfied as expected.',
            ];
        }

        // A health/risk block from RunRatioBotEvaluationJob's exchange/data
        // health guard (decisionService->block()) — metadata carries the
        // reason_code set in RunRatioBotEvaluationJob::healthBlock() (that
        // path also tags metadata.guard='exchange_health', so this check
        // must run before the generic guard branch below, not after it).
        if (isset($metadata['reason_code'])) {
            $category = self::mapReasonCodeToCategory((string) $metadata['reason_code']);

            return [
                'passed' => [],
                'failed' => [
                    ['rule' => (string) $metadata['reason_code'], 'detail' => $reason],
                ],
                'category' => $category,
                'confidence' => 'high',
                'expected' => 'Trading should pause while this health condition is active.',
                'actual' => 'Paused/blocked as expected.',
            ];
        }

        // A guarded HOLD — the trade signal was there but a downstream
        // guard vetoed it. See RatioBotDecisionService::finalizeTradeSignal().
        if (isset($metadata['guard'])) {
            $category = match ($metadata['guard']) {
                'max_allowed_slippage', 'minimum_net_profit', 'lot_profit_floor',
                'duplicate_unresolved_trade', 'trade_rate_limit' => RootCauseCategory::STRATEGY_RULE,
                default => RootCauseCategory::STRATEGY_RULE,
            };

            return [
                'passed' => [],
                'failed' => [
                    ['rule' => (string) $metadata['guard'], 'detail' => $reason],
                ],
                'category' => $category,
                'confidence' => 'high',
                'expected' => 'Hold: a configured guard (' . $metadata['guard'] . ') should block this trade.',
                'actual' => 'Held — guard fired as configured.',
            ];
        }

        // Risk-service block (RatioBotRiskService::preTradeBlockReason()).
        if (isset($metadata['risk_block']['code'])) {
            $code = (string) $metadata['risk_block']['code'];
            $category = self::mapReasonCodeToCategory($code);

            return [
                'passed' => [],
                'failed' => [
                    ['rule' => $code, 'detail' => (string) ($metadata['risk_block']['detail'] ?? $reason)],
                ],
                'category' => $category,
                'confidence' => 'high',
                'expected' => 'Risk guard should block this decision while ' . $code . ' is active.',
                'actual' => 'Blocked as expected.',
            ];
        }

        // Cooldown / generic bot-blocked pause.
        if ($decision->decision === RatioBotDecision::DECISION_COOLDOWN) {
            return [
                'passed' => [],
                'failed' => [
                    ['rule' => 'cooldown_window', 'detail' => $reason],
                ],
                'category' => RootCauseCategory::RISK_BLOCK,
                'confidence' => 'high',
                'expected' => 'No new trade while the post-trade cooldown window is active.',
                'actual' => 'Cooldown honored as expected.',
            ];
        }

        if ($decision->decision === RatioBotDecision::DECISION_PAUSE || $decision->decision === RatioBotDecision::DECISION_BLOCK) {
            return [
                'passed' => [],
                'failed' => [
                    ['rule' => 'bot_blocked', 'detail' => $reason],
                ],
                'category' => RootCauseCategory::RISK_BLOCK,
                'confidence' => 'medium',
                'expected' => 'No trade while the bot is paused/blocked.',
                'actual' => 'Paused/blocked as expected.',
            ];
        }

        // Plain HOLD — the ratio simply hasn't reached its configured
        // threshold yet. Correct, uneventful behavior.
        return [
            'passed' => [],
            'failed' => [
                ['rule' => 'ratio_threshold_not_reached', 'detail' => $reason],
            ],
            'category' => RootCauseCategory::CORRECT_BEHAVIOR,
            'confidence' => 'high',
            'expected' => 'Hold until the configured buy/sell ratio threshold is reached.',
            'actual' => 'Held — threshold not yet reached, as expected.',
        ];
    }

    private static function classifyAutoStartContext(array $context): array
    {
        $reason = (string) ($context['reason'] ?? '');
        $direction = $context['direction'] ?? null;
        $walletFallback = (array) ($context['wallet_fallback'] ?? []);

        if ($direction !== null) {
            return [
                'passed' => [
                    ['rule' => 'auto_start_direction_resolution', 'detail' => $reason ?: 'direction resolved'],
                ],
                'failed' => [],
                'category' => RootCauseCategory::CORRECT_BEHAVIOR,
                'confidence' => 'high',
                'expected' => 'Select a starting direction once confidence/trend/forecast agree.',
                'actual' => 'Direction selected: ' . $direction,
            ];
        }

        if (($walletFallback['action'] ?? null) !== null && ($walletFallback['action'] ?? null) !== 'flip') {
            return [
                'passed' => [],
                'failed' => [
                    ['rule' => 'wallet_aware_direction_fallback', 'detail' => $reason],
                ],
                'category' => RootCauseCategory::BALANCE_INSUFFICIENT,
                'confidence' => 'high',
                'expected' => 'Fall back to the opposite side only if wallet balance allows it.',
                'actual' => 'Blocked waiting — insufficient balance for the wallet-aware fallback side.',
            ];
        }

        if (str_contains($reason, 'insufficient') || str_contains($reason, 'needs base-asset') || str_contains($reason, 'needs quote-asset')) {
            return [
                'passed' => [],
                'failed' => [
                    ['rule' => 'wallet_balance_check', 'detail' => $reason],
                ],
                'category' => RootCauseCategory::BALANCE_INSUFFICIENT,
                'confidence' => 'high',
                'expected' => 'Only anchor a direction the wallet can actually fund.',
                'actual' => 'Waiting — required balance is not available.',
            ];
        }

        if (str_contains($reason, 'confidence') && str_contains($reason, 'below required')) {
            return [
                'passed' => [],
                'failed' => [
                    ['rule' => 'auto_start_min_confidence', 'detail' => $reason],
                ],
                'category' => RootCauseCategory::STRATEGY_RULE,
                'confidence' => 'high',
                'expected' => 'Only auto-start once forecast confidence clears the configured minimum.',
                'actual' => 'Waiting — confidence below the configured minimum.',
            ];
        }

        if (str_contains($reason, 'does not match forecast') || str_contains($reason, 'graph trend')) {
            return [
                'passed' => [],
                'failed' => [
                    ['rule' => 'trend_forecast_agreement', 'detail' => $reason],
                ],
                'category' => RootCauseCategory::DIRECTION_ERROR,
                'confidence' => 'medium',
                'expected' => 'Only auto-start once trend and forecast direction agree (or reliability-weighting resolves them).',
                'actual' => 'Waiting — trend/forecast disagreement unresolved.',
            ];
        }

        return [
            'passed' => [],
            'failed' => [
                ['rule' => 'auto_start_direction_wait', 'detail' => $reason],
            ],
            'category' => RootCauseCategory::UNKNOWN,
            'confidence' => 'low',
            'expected' => null,
            'actual' => 'Waiting — ' . ($reason ?: 'no direction resolved yet'),
        ];
    }

    private static function mapReasonCodeToCategory(string $code): string
    {
        return match (strtoupper($code)) {
            'STALE_FEED' => RootCauseCategory::DATA_STALE,
            'FEED_DEGRADED' => RootCauseCategory::DATA_STALE,
            'EXCHANGE_UNHEALTHY', 'WEBSOCKET_UNHEALTHY' => RootCauseCategory::FEED_DISCONNECTED,
            'ORDER_FAILURE_STREAK' => RootCauseCategory::WORKER_FAILURE,
            'RECONCILIATION_MISMATCH' => RootCauseCategory::RISK_BLOCK,
            'API_LATENCY_HIGH' => RootCauseCategory::API_LATENCY,
            'KILL_SWITCH', 'COOLDOWN', 'MARKET_CLOSED',
            'LIQUIDATION_GUARD_BLOCK_NEW_ENTRIES',
            'RATIO_BRAIN_EMERGENCY_BLOCK_NEW_ENTRIES' => RootCauseCategory::RISK_BLOCK,
            default => RootCauseCategory::RISK_BLOCK,
        };
    }
}
