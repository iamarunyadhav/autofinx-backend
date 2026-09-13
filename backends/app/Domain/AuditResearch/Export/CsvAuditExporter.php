<?php

namespace App\Domain\AuditResearch\Export;

use App\Domain\AuditResearch\SecretFieldScrubber;

/**
 * One reusable exporter, replacing the ad-hoc hand-built CSV strings this
 * app has today (AnalyticsController::export(), TradingController's own
 * copy) with a shared, escaping-safe implementation. Exports the "events"
 * table of the report — the flattest, most CSV-friendly section — plus a
 * short summary header.
 */
class CsvAuditExporter
{
    public function export(array $report): string
    {
        $report = SecretFieldScrubber::scrub($report);
        $lines = [];

        $lines[] = $this->row(['section', 'key', 'value']);
        foreach ((array) ($report['executive_summary'] ?? []) as $key => $value) {
            $lines[] = $this->row(['executive_summary', $key, $this->stringify($value)]);
        }

        $lines[] = $this->row([]);
        $lines[] = $this->row(['id', 'occurred_at', 'event_type', 'event_category', 'severity', 'correlation_id', 'symbol', 'market_price']);
        foreach ((array) ($report['events'] ?? []) as $event) {
            $lines[] = $this->row([
                $event['id'] ?? '',
                $event['occurred_at'] ?? '',
                $event['event_type'] ?? '',
                $event['event_category'] ?? '',
                $event['severity'] ?? '',
                $event['correlation_id'] ?? '',
                $event['symbol'] ?? '',
                $event['market_price'] ?? '',
            ]);
        }

        $lines[] = $this->row([]);
        $lines[] = $this->row(['id', 'correlation_id', 'decision', 'evaluated_price', 'execution_status', 'reason', 'decided_at']);
        foreach ((array) ($report['decisions'] ?? []) as $decision) {
            $lines[] = $this->row([
                $decision['id'] ?? '',
                $decision['correlation_id'] ?? '',
                $decision['decision'] ?? '',
                $decision['evaluated_price'] ?? '',
                $decision['execution_status'] ?? '',
                $decision['reason'] ?? '',
                $decision['decided_at'] ?? '',
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    private function row(array $fields): string
    {
        return implode(',', array_map([$this, 'escape'], $fields));
    }

    private function escape(mixed $field): string
    {
        $field = (string) $field;
        if (str_contains($field, ',') || str_contains($field, '"') || str_contains($field, "\n")) {
            return '"' . str_replace('"', '""', $field) . '"';
        }

        return $field;
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value) ?: '';
        }

        return (string) $value;
    }
}
