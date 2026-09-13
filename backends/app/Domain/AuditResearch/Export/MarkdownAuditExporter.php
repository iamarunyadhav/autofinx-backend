<?php

namespace App\Domain\AuditResearch\Export;

use App\Domain\AuditResearch\SecretFieldScrubber;

/**
 * The AI-friendly export — structured markdown with metadata/health/events/
 * decisions/trades/predictions/incidents/recommendations/blockers sections.
 * Explicitly excludes any secret/credential-shaped field via
 * SecretFieldScrubber, same as every other exporter.
 */
class MarkdownAuditExporter
{
    public function export(array $report): string
    {
        $report = SecretFieldScrubber::scrub($report);
        $session = (array) ($report['session'] ?? []);
        $lines = [];

        $lines[] = '# Audit Research Report — ' . ($session['name'] ?? 'Session');
        $lines[] = '';
        $lines[] = '## Metadata';
        foreach ($session as $key => $value) {
            $lines[] = "- **{$key}**: " . $this->stringify($value);
        }

        $lines[] = '';
        $lines[] = '## Executive Summary';
        foreach ((array) ($report['executive_summary'] ?? []) as $key => $value) {
            $lines[] = "- **{$key}**: " . $this->stringify($value);
        }

        $lines[] = '';
        $lines[] = '## Health';
        $blockers = (array) ($report['blockers'] ?? []);
        $lines[] = $blockers === [] ? '- No health blockers recorded.' : '- Blockers: ' . implode(', ', $blockers);

        $lines[] = '';
        $lines[] = '## Trading Summary';
        foreach ((array) ($report['trading_summary'] ?? []) as $key => $value) {
            $lines[] = "- **{$key}**: " . $this->stringify($value);
        }

        $lines[] = '';
        $lines[] = '## Events';
        foreach ((array) ($report['events'] ?? []) as $event) {
            $lines[] = sprintf(
                '- `%s` [%s/%s] %s — %s',
                $event['occurred_at'] ?? '',
                $event['event_category'] ?? '',
                $event['severity'] ?? '',
                $event['event_type'] ?? '',
                $event['correlation_id'] ?? ''
            );
        }
        if (($report['events'] ?? []) === []) {
            $lines[] = '- No events recorded.';
        }

        $lines[] = '';
        $lines[] = '## Decisions / Trades';
        foreach ((array) ($report['decisions'] ?? []) as $decision) {
            $lines[] = sprintf(
                '- `%s` %s @ %s (%s) — %s',
                $decision['decided_at'] ?? '',
                $decision['decision'] ?? '',
                $decision['evaluated_price'] ?? '',
                $decision['execution_status'] ?? '',
                $decision['reason'] ?? ''
            );
        }
        if (($report['decisions'] ?? []) === []) {
            $lines[] = '- No decisions recorded.';
        }

        $lines[] = '';
        $lines[] = '## Prediction Analysis';
        foreach ((array) ($report['prediction_analysis'] ?? []) as $horizon) {
            $lines[] = sprintf(
                '- horizon=%ss hit_rate=%s%% sample_size=%s computed_at=%s',
                $horizon['horizon_seconds'] ?? '',
                $horizon['hit_rate_pct'] ?? 'n/a',
                $horizon['sample_size'] ?? 0,
                $horizon['computed_at'] ?? ''
            );
        }
        if (($report['prediction_analysis'] ?? []) === []) {
            $lines[] = '- No forecast backtests available yet for this bot.';
        }

        $lines[] = '';
        $lines[] = '## Incidents';
        foreach ((array) ($report['incident_analysis'] ?? []) as $incident) {
            $lines[] = sprintf(
                '- [%s] %s — %s (occurrences=%s, self_recovered=%s)',
                $incident['severity'] ?? '',
                $incident['title'] ?? '',
                $incident['root_cause_category'] ?? '',
                $incident['occurrence_count'] ?? 1,
                ($incident['self_recovered'] ?? false) ? 'yes' : 'no'
            );
        }
        if (($report['incident_analysis'] ?? []) === []) {
            $lines[] = '- No incidents recorded.';
        }

        $lines[] = '';
        $lines[] = '## Recommendations';
        $recommendations = (array) ($report['recommendations'] ?? []);
        if ($recommendations === []) {
            $lines[] = '- None.';
        } else {
            foreach ($recommendations as $recommendation) {
                $lines[] = '- ' . $recommendation;
            }
        }

        $lines[] = '';
        $lines[] = '## Blockers';
        $lines[] = $blockers === [] ? '- None.' : implode("\n", array_map(fn ($b) => "- {$b}", $blockers));

        return implode("\n", $lines) . "\n";
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value) ?: '';
        }

        return (string) ($value ?? 'n/a');
    }
}
