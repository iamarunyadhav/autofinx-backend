<?php

namespace App\Domain\AuditResearch\Export;

use App\Domain\AuditResearch\SecretFieldScrubber;
use Barryvdh\DomPDF\PDF;

/**
 * Fourth exporter, consuming the exact same AuditReportBuilder::buildForSession()
 * shape the CSV/JSON/Markdown exporters already use -- no data-shaping logic
 * is duplicated here, only presentation. Renders via barryvdh/laravel-dompdf
 * (composer require barryvdh/laravel-dompdf:^3.1 -- installed successfully
 * in this environment, see composer.lock: dompdf/dompdf v3.1.6).
 *
 * Same secret-scrub guarantee as every other exporter: SecretFieldScrubber
 * runs on the report before a single line of HTML is built.
 */
class PdfAuditExporter
{
    public function __construct(private readonly PDF $pdf)
    {
    }

    public function export(array $report): string
    {
        $report = SecretFieldScrubber::scrub($report);
        $html = $this->renderHtml($report);

        $this->pdf->loadHTML($html);

        return (string) $this->pdf->output();
    }

    private function renderHtml(array $report): string
    {
        $session = (array) ($report['session'] ?? []);
        $title = 'Audit Research Report — ' . $this->e($session['name'] ?? 'Session');

        $sections = [];
        $sections[] = $this->metadataSection($session);
        $sections[] = $this->kvSection('Executive Summary', (array) ($report['executive_summary'] ?? []));
        $sections[] = $this->kvSection('Trading Summary', (array) ($report['trading_summary'] ?? []));
        $sections[] = $this->predictionSection((array) ($report['prediction_analysis'] ?? []));
        $sections[] = $this->incidentSection((array) ($report['incident_analysis'] ?? []));
        $sections[] = $this->kvSection('Financial Impact', (array) ($report['financial_impact'] ?? []));
        $sections[] = $this->recommendationsSection((array) ($report['recommendations'] ?? []));

        $body = implode("\n", $sections);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1a1a1a; }
    h1 { font-size: 18px; margin-bottom: 4px; }
    h2 { font-size: 14px; margin-top: 18px; margin-bottom: 6px; border-bottom: 1px solid #ccc; padding-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    td, th { text-align: left; padding: 3px 6px; border-bottom: 1px solid #eee; vertical-align: top; }
    .label { font-weight: bold; width: 220px; }
    .empty { color: #777; font-style: italic; }
</style>
</head>
<body>
<h1>{$title}</h1>
{$body}
</body>
</html>
HTML;
    }

    private function metadataSection(array $session): string
    {
        return $this->kvSection('Metadata', $session);
    }

    private function kvSection(string $title, array $rows): string
    {
        if ($rows === []) {
            return "<h2>{$this->e($title)}</h2><p class=\"empty\">No data.</p>";
        }

        $lines = ["<h2>{$this->e($title)}</h2>", '<table>'];
        foreach ($rows as $key => $value) {
            $lines[] = sprintf(
                '<tr><td class="label">%s</td><td>%s</td></tr>',
                $this->e((string) $key),
                $this->e($this->stringify($value))
            );
        }
        $lines[] = '</table>';

        return implode("\n", $lines);
    }

    private function predictionSection(array $horizons): string
    {
        if ($horizons === []) {
            return '<h2>Prediction Analysis</h2><p class="empty">No forecast backtests available yet for this bot.</p>';
        }

        $lines = ['<h2>Prediction Analysis</h2>', '<table>', '<tr><th>Horizon (s)</th><th>Hit Rate %</th><th>Sample Size</th><th>Computed At</th></tr>'];
        foreach ($horizons as $h) {
            $lines[] = sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->e((string) ($h['horizon_seconds'] ?? '')),
                $this->e((string) ($h['hit_rate_pct'] ?? 'n/a')),
                $this->e((string) ($h['sample_size'] ?? 0)),
                $this->e((string) ($h['computed_at'] ?? ''))
            );
        }
        $lines[] = '</table>';

        return implode("\n", $lines);
    }

    private function incidentSection(array $incidents): string
    {
        if ($incidents === []) {
            return '<h2>Incident Analysis</h2><p class="empty">No incidents recorded.</p>';
        }

        $lines = ['<h2>Incident Analysis</h2>', '<table>', '<tr><th>Severity</th><th>Title</th><th>Root Cause</th><th>Occurrences</th><th>Self-Recovered</th></tr>'];
        foreach ($incidents as $i) {
            $lines[] = sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                $this->e((string) ($i['severity'] ?? '')),
                $this->e((string) ($i['title'] ?? '')),
                $this->e((string) ($i['root_cause_category'] ?? '')),
                $this->e((string) ($i['occurrence_count'] ?? 1)),
                ($i['self_recovered'] ?? false) ? 'yes' : 'no'
            );
        }
        $lines[] = '</table>';

        return implode("\n", $lines);
    }

    private function recommendationsSection(array $recommendations): string
    {
        if ($recommendations === []) {
            return '<h2>Recommendations</h2><p class="empty">None.</p>';
        }

        $lines = ['<h2>Recommendations</h2>', '<ul>'];
        foreach ($recommendations as $r) {
            $lines[] = '<li>' . $this->e((string) $r) . '</li>';
        }
        $lines[] = '</ul>';

        return implode("\n", $lines);
    }

    private function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value) ?: '';
        }

        return (string) ($value ?? 'n/a');
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
