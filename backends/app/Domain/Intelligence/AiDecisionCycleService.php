<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Data\AiTradeProposal;
use App\Domain\Portfolio\PortfolioRecommendationService;
use App\Models\AiDecision;
use App\Models\AiDecisionCycle;
use App\Models\AiTradingConfig;
use App\Models\AiTradingState;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiDecisionCycleService
{
    public function __construct(
        private readonly AiContextBuilderService $contextBuilder,
        private readonly AiDecisionOrchestratorService $orchestrator,
        private readonly AiTradePolicyService $policy,
        private readonly AiDecisionAuditService $audit,
        private readonly AiDecisionExecutionService $execution,
        private readonly AiTradingStateService $state,
        private readonly AiAnomalyDetectionService $anomalies,
        private readonly AiNotificationSummaryService $notifications,
        private readonly PortfolioRecommendationService $portfolioIntelligence,
    ) {
    }

    public function run(string $workspaceId, string $symbol, array $options = []): array
    {
        $symbol = strtoupper(trim($symbol));
        $config = $this->configForWorkspace($workspaceId);
        $manualTrigger = (bool) ($options['manual_trigger'] ?? false);
        $actorId = $options['actor_id'] ?? null;
        $correlationId = (string) ($options['correlation_id'] ?? $this->correlationId($workspaceId, $symbol, $manualTrigger));

        $cycle = AiDecisionCycle::firstOrCreate(
            ['correlation_id' => $correlationId],
            [
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspaceId,
                'ai_trading_config_id' => $config->id,
                'triggered_by' => $actorId,
                'integration_id' => $options['integration_id'] ?? null,
                'trigger_type' => $manualTrigger ? 'manual' : 'scheduled',
                'mode' => $config->mode,
                'symbol' => $symbol,
                'status' => AiDecisionCycle::STATUS_STARTED,
                'started_at' => now(),
            ]
        );

        if ($cycle->finished_at) {
            return [
                'cycle' => $cycle,
                'decision' => $cycle->decision,
                'order' => null,
                'message' => $cycle->message ?: 'Decision cycle already processed.',
            ];
        }

        Log::withContext([
            'workspace_id' => $workspaceId,
            'symbol' => $symbol,
            'correlation_id' => $correlationId,
            'manual_trigger' => $manualTrigger,
        ])->info('ai_decision_cycle.started');

        if (! $config->enabled) {
            return $this->finalize($cycle, AiDecisionCycle::STATUS_BLOCKED, 'AI trading is disabled for this workspace.', 'config_disabled');
        }

        $stateSnapshot = $this->state->snapshot($workspaceId, $symbol, $config->toArray());
        if (! ($stateSnapshot['allowed'] ?? false)) {
            $message = implode(',', (array) ($stateSnapshot['reason_codes'] ?? [])) ?: 'state_blocked';
            $this->notifications->forCycle($cycle, 'AI cycle blocked', $message, 'warning', [
                'reason_codes' => $stateSnapshot['reason_codes'] ?? [],
            ]);

            return $this->finalize($cycle, AiDecisionCycle::STATUS_PAUSED, $message, $message, [
                'state_snapshot_json' => $stateSnapshot,
            ]);
        }

        $cadence = $this->state->enforceCadence($workspaceId, $symbol, $config->toArray(), $manualTrigger, $cycle->id);
        if (! ($cadence['allowed'] ?? false)) {
            $message = implode(',', (array) ($cadence['reason_codes'] ?? [])) ?: 'decision_rate_limited';
            $this->state->recordCycle($workspaceId, $symbol, AiTradingState::STATUS_COOLING_DOWN);

            return $this->finalize($cycle, AiDecisionCycle::STATUS_SKIPPED, $message, $message, [
                'state_snapshot_json' => $stateSnapshot,
            ]);
        }

        $context = $this->contextBuilder->build($workspaceId, $symbol, [
            'integration_id' => $options['integration_id'] ?? null,
            'timeframe' => $options['timeframe'] ?? '1m',
            'exchange' => 'binance',
        ]);
        try {
            $portfolioSnapshot = $this->portfolioIntelligence->latestSnapshot($workspaceId);
        } catch (\Throwable $e) {
            $portfolioSnapshot = [
                'available' => false,
                'error' => $e->getMessage(),
            ];
        }

        $context['portfolio_intelligence'] = [
            'available' => ! empty(data_get($portfolioSnapshot, 'summary', [])),
            'generated_at' => data_get($portfolioSnapshot, 'generated_at'),
            'summary' => data_get($portfolioSnapshot, 'summary', []),
            'risk_budget' => data_get($portfolioSnapshot, 'risk_budget', []),
            'ranked_opportunities' => array_slice((array) data_get($portfolioSnapshot, 'ranked_opportunities', []), 0, 10),
            'selected' => array_slice((array) data_get($portfolioSnapshot, 'selection.selected', []), 0, 10),
            'reduce_recommendations' => array_slice((array) data_get($portfolioSnapshot, 'selection.reduce_recommendations', []), 0, 10),
            'allocation' => data_get($portfolioSnapshot, 'allocation', []),
            'external_context' => data_get($portfolioSnapshot, 'portfolio_context.portfolio.external_context', []),
        ];
        $cycle->context_summary_json = $context;
        $cycle->integration_id = data_get($context, 'integration.id');

        $stateSnapshot = $this->state->snapshot($workspaceId, $symbol, $config->toArray(), $context);
        $anomalyReport = $this->anomalies->detect($workspaceId, $symbol, $config->toArray(), $context, $stateSnapshot);
        $cycle->anomalies_json = $anomalyReport;
        $cycle->state_snapshot_json = $stateSnapshot;

        $pauseState = $this->state->applyAnomalies($workspaceId, $symbol, $config->toArray(), $anomalyReport, $actorId);
        if ($pauseState) {
            $this->notifications->forCycle($cycle, 'AI trading paused', (string) ($pauseState->reason_detail ?: $pauseState->reason_code), 'destructive', [
                'reason_code' => $pauseState->reason_code,
            ]);

            return $this->finalize($cycle, AiDecisionCycle::STATUS_PAUSED, (string) ($pauseState->reason_code ?: 'paused'), (string) ($pauseState->reason_code ?: 'paused'));
        }

        $orchestrated = $this->orchestrator->propose($context, $config->toArray());
        $cycle->provider_name = $orchestrated['provider'] ?? null;
        $cycle->provider_model = $orchestrated['model'] ?? null;
        $cycle->validation_result_json = $orchestrated['validation'] ?? null;

        if (! ($orchestrated['ok'] ?? false)) {
            $message = (string) implode(',', (array) data_get($orchestrated, 'validation.errors', ['provider_failure']));
            $this->notifications->forCycle($cycle, 'AI provider unavailable', $message, 'warning');

            return $this->finalize($cycle, AiDecisionCycle::STATUS_FAILED, $message, 'provider_failure');
        }

        /** @var AiTradeProposal $proposal */
        $proposal = $orchestrated['proposal'];
        if ($proposal->isPauseAction()) {
            $paused = $this->state->pause(
                $workspaceId,
                $symbol,
                AiTradingState::REASON_LOW_CONFIDENCE_ENVIRONMENT,
                $proposal->reasoningSummary,
                $actorId,
                null,
                (bool) ($config->manual_resume_required ?? false),
                ['warnings' => $proposal->warnings]
            );
            $this->notifications->forCycle($cycle, 'AI requested trading pause', $proposal->reasoningSummary, 'warning', [
                'reason_code' => $paused->reason_code,
                'proposal' => $proposal->toArray(),
            ]);

            return $this->finalize($cycle, AiDecisionCycle::STATUS_PAUSED, $proposal->reasoningSummary, 'pause_trading_proposal', [
                'validation_result_json' => $orchestrated['validation'],
            ]);
        }

        // REVIEW_REQUIRED — AI explicitly flagged this as needing operator review.
        // Record as pending approval regardless of mode; cannot auto-execute.
        if ($proposal->requiresReview()) {
            $decision = $this->audit->record([
                'workspace_id' => $workspaceId,
                'requested_by' => $actorId,
                'integration_id' => $cycle->integration_id,
                'exchange' => 'binance',
                'market_type' => 'spot',
                'operator_mode' => $config->mode,
                'symbol' => $symbol,
                'action' => $proposal->action,
                'confidence' => $proposal->confidence,
                'context_summary_json' => $context,
                'blockers_json' => [],
                'raw_proposal_text' => $orchestrated['raw_text'],
                'raw_proposal_json' => $orchestrated['raw_json'],
                'validated_proposal_json' => $proposal->toArray(),
                'validation_result_json' => $orchestrated['validation'],
                'policy_result_json' => [
                    'allowed' => true,
                    'reason_codes' => ['review_required_by_ai'],
                    'min_confidence' => (float) ($config->min_confidence ?? 0),
                    'confidence_gap' => (float) ($config->min_confidence ?? 0) > 0
                        ? round($proposal->confidence - (float) ($config->min_confidence ?? 0), 6)
                        : null,
                ],
                'provider_name' => $orchestrated['provider'],
                'provider_model' => $orchestrated['model'],
                'requires_approval' => true,
                'final_status' => AiDecision::STATUS_PENDING_APPROVAL,
                'rejection_reason' => null,
                'expires_at' => now()->addMinutes((int) config('intelligence.ai_trading.proposal_expiry_minutes', 15)),
            ], $actorId);
            $cycle->ai_decision_id = $decision->id;
            $cycle->save();

            $this->notifications->forCycle($cycle, 'AI flagged for operator review', $proposal->reasoningSummary, 'warning', [
                'decision_id' => $decision->id,
                'confidence' => $proposal->confidence,
                'warnings' => $proposal->warnings,
            ]);

            return $this->finalize($cycle, AiDecisionCycle::STATUS_PENDING_APPROVAL, 'AI requested operator review before any action.', 'review_required');
        }

        $policyResult = $this->policy->evaluate($workspaceId, $config->toArray(), $context, $proposal);
        $cycle->policy_result_json = $policyResult;

        $finalStatus = AiDecision::STATUS_RECORDED;
        if (! ($policyResult['allowed'] ?? false)) {
            $finalStatus = AiDecision::STATUS_BLOCKED;
        } elseif ($policyResult['requires_human_approval'] ?? false) {
            $finalStatus = AiDecision::STATUS_PENDING_APPROVAL;
        }

        $decision = $this->audit->record([
            'workspace_id' => $workspaceId,
            'requested_by' => $actorId,
            'integration_id' => $cycle->integration_id,
            'exchange' => 'binance',
            'market_type' => 'spot',
            'operator_mode' => $config->mode,
            'symbol' => $symbol,
            'action' => $proposal->action,
            'confidence' => $proposal->confidence,
            'context_summary_json' => $context,
            'blockers_json' => array_values((array) ($context['blockers'] ?? [])),
            'raw_proposal_text' => $orchestrated['raw_text'],
            'raw_proposal_json' => $orchestrated['raw_json'],
            'validated_proposal_json' => $proposal->toArray(),
            'validation_result_json' => $orchestrated['validation'],
            'policy_result_json' => $policyResult,
            'provider_name' => $orchestrated['provider'],
            'provider_model' => $orchestrated['model'],
            'requires_approval' => (bool) ($policyResult['requires_human_approval'] ?? false),
            'final_status' => $finalStatus,
            'rejection_reason' => ! ($policyResult['allowed'] ?? false) ? implode(',', (array) ($policyResult['reason_codes'] ?? [])) : null,
            'expires_at' => ($policyResult['requires_human_approval'] ?? false)
                ? now()->addMinutes((int) config('intelligence.ai_trading.proposal_expiry_minutes', 15))
                : null,
        ], $actorId);

        $cycle->ai_decision_id = $decision->id;
        $cycle->save();

        if (! ($policyResult['allowed'] ?? false)) {
            $this->notifications->forCycle($cycle, 'AI trade rejected', (string) $decision->rejection_reason, 'destructive', [
                'reason_codes' => $policyResult['reason_codes'] ?? [],
                'decision_id' => $decision->id,
            ]);

            return $this->finalize($cycle, AiDecisionCycle::STATUS_REJECTED, 'AI proposal rejected by policy.', (string) ($decision->rejection_reason ?: 'policy_rejected'));
        }

        if ($policyResult['requires_human_approval'] ?? false) {
            $this->notifications->forCycle($cycle, 'AI trade awaiting approval', $proposal->reasoningSummary, 'warning', [
                'decision_id' => $decision->id,
                'action' => $proposal->action,
                'confidence' => $proposal->confidence,
            ]);

            return $this->finalize($cycle, AiDecisionCycle::STATUS_PENDING_APPROVAL, 'AI proposal is pending operator approval.', null);
        }

        if (! ($policyResult['can_execute_now'] ?? false)) {
            return $this->finalize($cycle, AiDecisionCycle::STATUS_NO_TRADE, 'AI proposal recorded without execution.', null);
        }

        $result = $this->execution->execute($decision, $context, $policyResult, $config->toArray(), $actorId);
        $cycle = $cycle->fresh();

        if (! ($result['ok'] ?? false)) {
            $this->notifications->forCycle($cycle, 'AI auto trade failed', (string) ($result['message'] ?? 'execution_failed'), 'destructive', [
                'decision_id' => $decision->id,
            ]);

            return [
                'cycle' => $cycle,
                'decision' => $result['decision'] ?? $decision,
                'order' => null,
                'message' => $result['message'] ?? 'execution_failed',
            ];
        }

        $this->notifications->forCycle($cycle, 'AI auto trade submitted', $proposal->reasoningSummary, 'success', [
            'decision_id' => $decision->id,
            'order_id' => $result['order']?->id,
            'action' => $proposal->action,
            'confidence' => $proposal->confidence,
        ]);

        return [
            'cycle' => $cycle,
            'decision' => $result['decision'] ?? $decision,
            'order' => $result['order'] ?? null,
            'message' => $result['message'] ?? 'executed',
        ];
    }

    private function finalize(AiDecisionCycle $cycle, string $status, string $message, ?string $errorCode = null, array $overrides = []): array
    {
        $cycle->fill(array_merge([
            'status' => $status,
            'message' => $message,
            'error_code' => $errorCode,
            'finished_at' => now(),
            'duration_ms' => $cycle->started_at ? $cycle->started_at->diffInMilliseconds(now()) : null,
        ], $overrides))->save();

        $this->state->recordCycle((string) $cycle->workspace_id, $cycle->symbol, $status);
        Log::info('ai_decision_cycle.finished', [
            'workspace_id' => $cycle->workspace_id,
            'symbol' => $cycle->symbol,
            'correlation_id' => $cycle->correlation_id,
            'status' => $status,
            'error_code' => $errorCode,
        ]);

        return [
            'cycle' => $cycle->fresh(),
            'decision' => $cycle->decision,
            'order' => null,
            'message' => $message,
        ];
    }

    private function configForWorkspace(string $workspaceId): AiTradingConfig
    {
        return AiTradingConfig::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'id' => (string) Str::uuid(),
                'enabled' => false,
                'mode' => 'advisor',
                'exchange' => 'binance',
                'market_type' => 'spot',
                'provider' => config('intelligence.ai_trading.provider', 'heuristic'),
                'allowed_symbols' => [],
                'enabled_symbols' => [],
                'blocked_symbols' => [],
                'cooldown_minutes' => 0,
                'decision_interval_minutes' => 5,
                'max_pending_decisions_per_symbol' => 1,
                'max_decisions_per_hour_per_symbol' => 12,
                'max_decisions_per_hour' => 60,
                'auto_trade_enabled' => false,
                'require_stop_loss' => true,
                'require_take_profit' => true,
                'require_time_stop' => true,
                'auto_pause_on_daily_loss' => true,
                'auto_pause_on_drawdown' => true,
                'auto_pause_on_order_failure_streak' => 3,
                'auto_pause_on_slippage_spike' => true,
                'auto_pause_on_reconciliation_mismatch' => true,
                'manual_resume_required' => false,
            ]
        );
    }

    private function correlationId(string $workspaceId, string $symbol, bool $manualTrigger): string
    {
        if ($manualTrigger) {
            return 'manual:' . $workspaceId . ':' . $symbol . ':' . (string) Str::uuid();
        }

        return 'scheduled:' . $workspaceId . ':' . $symbol . ':' . now()->format('YmdHi');
    }
}
