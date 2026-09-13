<?php

namespace App\Domain\AuditResearch;

use App\Models\AuditEvent;
use App\Models\AuditSession;
use App\Models\RatioBotDecision;

/**
 * Side-by-side build/version comparison between two audit sessions in the
 * SAME workspace. Reuses AuditReportBuilder::buildForSession() for the
 * prediction/incident/execution shaping (no duplicated data logic) and adds
 * one real aggregate that report doesn't already expose: average
 * audit_events.latency_ms for the session window.
 */
class BuildComparisonService
{
    public function __construct(
        private readonly AuditReportBuilder $reportBuilder,
    ) {
    }

    public function compare(AuditSession $sessionA, AuditSession $sessionB): array
    {
        if ((string) $sessionA->workspace_id !== (string) $sessionB->workspace_id) {
            throw new \RuntimeException('Cannot compare audit sessions from different workspaces.');
        }

        $reportA = $this->reportBuilder->buildForSession($sessionA);
        $reportB = $this->reportBuilder->buildForSession($sessionB);

        return [
            'session_a' => $reportA['session'],
            'session_b' => $reportB['session'],
            'prediction_accuracy' => [
                'session_a' => $reportA['prediction_analysis'],
                'session_b' => $reportB['prediction_analysis'],
                'avg_hit_rate_pct' => [
                    'session_a' => $this->avgHitRate($reportA['prediction_analysis']),
                    'session_b' => $this->avgHitRate($reportB['prediction_analysis']),
                ],
            ],
            'execution_failure_count' => [
                'session_a' => $this->executionFailureCount($reportA['decisions']),
                'session_b' => $this->executionFailureCount($reportB['decisions']),
            ],
            'average_decision_latency_ms' => [
                'session_a' => $this->averageLatencyMs($sessionA),
                'session_b' => $this->averageLatencyMs($sessionB),
            ],
            'root_cause_breakdown' => [
                'session_a' => $this->rootCauseBreakdown($reportA['incident_analysis']),
                'session_b' => $this->rootCauseBreakdown($reportB['incident_analysis']),
            ],
            'realized_pnl' => [
                'session_a' => $reportA['financial_impact']['realized_pnl'] ?? 0.0,
                'session_b' => $reportB['financial_impact']['realized_pnl'] ?? 0.0,
            ],
            'total_incidents' => [
                'session_a' => count($reportA['incident_analysis']),
                'session_b' => count($reportB['incident_analysis']),
            ],
        ];
    }

    /**
     * @param array<int, array{decision:string,execution_status:?string}> $decisions
     */
    private function executionFailureCount(array $decisions): int
    {
        return count(array_filter(
            $decisions,
            fn (array $d) => ($d['execution_status'] ?? null) === RatioBotDecision::EXEC_FAILED
        ));
    }

    private function averageLatencyMs(AuditSession $session): ?float
    {
        $avg = AuditEvent::query()
            ->where('audit_session_id', $session->id)
            ->whereNotNull('latency_ms')
            ->avg('latency_ms');

        return $avg !== null ? round((float) $avg, 2) : null;
    }

    /**
     * @param array<int, array{root_cause_category:?string}> $incidents
     * @return array<string, int>
     */
    private function rootCauseBreakdown(array $incidents): array
    {
        $breakdown = array_fill_keys(RootCauseCategory::ALL, 0);

        foreach ($incidents as $incident) {
            $category = $incident['root_cause_category'] ?? RootCauseCategory::UNKNOWN;
            if (! array_key_exists($category, $breakdown)) {
                $category = RootCauseCategory::UNKNOWN;
            }
            $breakdown[$category]++;
        }

        return $breakdown;
    }

    /**
     * @param array<int, array{hit_rate_pct:?float, sample_size:int}> $horizons
     */
    private function avgHitRate(array $horizons): ?float
    {
        $weighted = 0.0;
        $totalSamples = 0;

        foreach ($horizons as $h) {
            $sampleSize = (int) ($h['sample_size'] ?? 0);
            $hitRate = $h['hit_rate_pct'] ?? null;
            if ($sampleSize > 0 && $hitRate !== null) {
                $weighted += $hitRate * $sampleSize;
                $totalSamples += $sampleSize;
            }
        }

        return $totalSamples > 0 ? round($weighted / $totalSamples, 2) : null;
    }
}
