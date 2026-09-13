<?php

namespace App\Domain\AuditResearch;

use App\Models\AuditEvent;
use App\Models\AuditIncident;
use App\Models\AuditSession;
use App\Models\RatioBotConfig;
use App\Models\RatioBotDecision;
use App\Models\RatioBotLot;

/**
 * Builds one structured report object consumed by all three exporters
 * (CSV/JSON/Markdown) — the data-shaping logic lives here exactly once.
 *
 * Reuses existing aggregates rather than recomputing them:
 *  - trading summary: RatioBotDecision (the real decision ledger already in
 *    this app, not duplicated here).
 *  - prediction analysis: PredictionAccuracyService, itself a thin wrapper
 *    over the existing RatioBotForecastBacktest rows.
 *  - financial impact: RatioBotLot.realized_pnl, the existing per-lot FIFO
 *    accounting (RatioBotLotService) — not recomputed from raw fills.
 */
class AuditReportBuilder
{
    public function __construct(
        private readonly PredictionAccuracyService $predictionAccuracy,
    ) {
    }

    public function buildForSession(AuditSession $session): array
    {
        $config = $session->bot_instance_id ? RatioBotConfig::find($session->bot_instance_id) : null;

        $events = AuditEvent::query()
            ->where('audit_session_id', $session->id)
            ->orderBy('occurred_at')
            ->get();

        $decisions = $config
            ? RatioBotDecision::query()
                ->where('ratio_bot_config_id', $config->id)
                ->where('decided_at', '>=', $session->started_at ?? $session->created_at)
                ->when($session->ended_at, fn ($q) => $q->where('decided_at', '<=', $session->ended_at))
                ->orderBy('decided_at')
                ->get()
            : collect();

        $incidents = AuditIncident::query()
            ->where('workspace_id', $session->workspace_id)
            ->when($session->bot_instance_id, fn ($q) => $q->where('bot_instance_id', $session->bot_instance_id))
            ->where('first_detected_at', '>=', $session->started_at ?? $session->created_at)
            ->orderBy('first_detected_at')
            ->get();

        $realizedPnl = $config
            ? (float) RatioBotLot::query()->where('ratio_bot_config_id', $config->id)->sum('realized_pnl')
            : 0.0;

        $tradeDecisions = $decisions->filter(fn (RatioBotDecision $d) => $d->isTrade());
        $blockedDecisions = $decisions->filter(fn (RatioBotDecision $d) => in_array($d->decision, [
            RatioBotDecision::DECISION_BLOCK,
            RatioBotDecision::DECISION_PAUSE,
        ], true));

        $predictionAnalysis = $config ? $this->predictionAccuracy->forConfig($config) : [];

        $blockers = $events->where('event_category', 'health')
            ->merge($events->where('event_category', 'incident'))
            ->pluck('event_type')
            ->unique()
            ->values()
            ->all();

        $recommendations = [];
        foreach ($incidents as $incident) {
            if ($incident->recommended_action) {
                $recommendations[] = $incident->recommended_action;
            }
        }

        return [
            'session' => [
                'id' => $session->id,
                'name' => $session->name,
                'bot_type' => $session->bot_type,
                'bot_instance_id' => $session->bot_instance_id,
                'environment' => $session->environment,
                'symbol' => $session->symbol,
                'mode' => $session->mode,
                'status' => $session->status,
                'started_at' => optional($session->started_at)->toIso8601String(),
                'ended_at' => optional($session->ended_at)->toIso8601String(),
                'build_version' => $session->build_version,
            ],
            'executive_summary' => [
                'total_events' => $events->count(),
                'total_decisions' => $decisions->count(),
                'total_trades' => $tradeDecisions->count(),
                'total_blocks' => $blockedDecisions->count(),
                'total_incidents' => $incidents->count(),
                'realized_pnl' => round($realizedPnl, 8),
            ],
            'trading_summary' => [
                'decision_counts' => $decisions->countBy('decision')->all(),
                'trade_count' => $tradeDecisions->count(),
                'realized_pnl' => round($realizedPnl, 8),
            ],
            'prediction_analysis' => $predictionAnalysis,
            'incident_analysis' => $incidents->map(fn (AuditIncident $i) => [
                'id' => $i->id,
                'title' => $i->title,
                'root_cause_category' => $i->root_cause_category,
                'severity' => $i->severity,
                'status' => $i->status,
                'occurrence_count' => $i->occurrence_count,
                'self_recovered' => $i->self_recovered,
                'first_detected_at' => optional($i->first_detected_at)->toIso8601String(),
                'recovered_at' => optional($i->recovered_at)->toIso8601String(),
            ])->values()->all(),
            'financial_impact' => [
                'realized_pnl' => round($realizedPnl, 8),
            ],
            'health' => [
                'blockers' => $blockers,
            ],
            'events' => $events->map(fn (AuditEvent $e) => [
                'id' => $e->id,
                'occurred_at' => optional($e->occurred_at)->toIso8601String(),
                'event_type' => $e->event_type,
                'event_category' => $e->event_category,
                'severity' => $e->severity,
                'correlation_id' => $e->correlation_id,
                'symbol' => $e->symbol,
                'market_price' => $e->market_price,
                'metadata' => SecretFieldScrubber::scrub((array) ($e->metadata ?? [])),
            ])->values()->all(),
            'decisions' => $decisions->map(fn (RatioBotDecision $d) => [
                'id' => $d->id,
                'correlation_id' => $d->correlation_id,
                'decision' => $d->decision,
                'evaluated_price' => $d->evaluated_price,
                'execution_status' => $d->execution_status,
                'reason' => $d->reason,
                'decided_at' => optional($d->decided_at)->toIso8601String(),
            ])->values()->all(),
            'recommendations' => array_values(array_unique($recommendations)),
            'blockers' => $blockers,
        ];
    }
}
