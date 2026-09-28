<?php

namespace App\Domain\Prediction;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class PredictionReportExporter
{
    /** @param array<string, mixed> $report */
    public function csv(array $report): StreamedResponse
    {
        $rows = (array) ($report['timeline'] ?? []);
        return response()->streamDownload(static function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['provider', 'model_version', 'symbol', 'timeframe', 'horizon_seconds', 'predicted_at', 'evaluated_at', 'predicted_direction', 'entry_price', 'expiry_price', 'return_pct', 'direction_correct']);
            foreach ($rows as $row) {
                fputcsv($handle, array_map(static fn (string $key): mixed => $row[$key] ?? null, [
                    'provider', 'model_version', 'symbol', 'timeframe', 'horizon_seconds', 'predicted_at', 'evaluated_at', 'predicted_direction', 'entry_price', 'expiry_price', 'return_pct', 'direction_correct',
                ]));
            }
            fclose($handle);
        }, 'autofinx-prediction-report.csv', ['Content-Type' => 'text/csv']);
    }

    /** @param array<string, mixed> $report */
    public function markdown(array $report): string
    {
        $lines = ['# AutoFinX Prediction Report', '', 'Status: '.($report['status'] ?? 'UNKNOWN'), '', '## Provider summary', '', '| Provider | Samples | Evaluated | Direction accuracy | Status |', '|---|---:|---:|---:|---|'];
        foreach ((array) ($report['summary'] ?? []) as $row) {
            $lines[] = sprintf('| %s | %s | %s | %s | %s |', $row['provider'] ?? 'unknown', $row['samples'] ?? 0, $row['evaluated'] ?? 0, $row['direction_accuracy_pct'] ?? '—', $row['status'] ?? 'INSUFFICIENT_DATA');
        }
        $lines[] = ''; $lines[] = '## Timeline'; $lines[] = ''; $lines[] = '| Provider | Symbol | Timeframe | Decision | Entry | Expiry | Return % | Correct |'; $lines[] = '|---|---|---|---|---:|---:|---:|---|';
        foreach ((array) ($report['timeline'] ?? []) as $row) {
            $lines[] = sprintf('| %s | %s | %s | %s | %s | %s | %s | %s |', $row['provider'] ?? 'unknown', $row['symbol'] ?? '—', $row['timeframe'] ?? '—', $row['predicted_direction'] ?? '—', $row['entry_price'] ?? '—', $row['expiry_price'] ?? '—', $row['return_pct'] ?? '—', $row['direction_correct'] === null ? '—' : ($row['direction_correct'] ? 'yes' : 'no'));
        }
        return implode("\n", $lines)."\n";
    }
}
