<?php

namespace App\Domain\Intelligence;

use Illuminate\Support\Carbon;

class MacroEventContextService
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    public function normalize(array $items, string $providerName, ?string $workspaceSymbol = null): array
    {
        $normalized = [];

        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $scheduledAt = $this->parseTime($row['scheduled_at'] ?? null);
            $severity = $this->normalizeSeverity((string) ($row['severity'] ?? ExternalContextTaxonomy::SEVERITY_LOW));
            $impact = $this->normalizeImpact((string) ($row['impact'] ?? ExternalContextTaxonomy::IMPACT_CAUTION));

            $normalized[] = [
                'provider' => $providerName,
                'category' => ExternalContextTaxonomy::CATEGORY_MACRO_EVENT,
                'severity' => $severity,
                'impact_flag' => $impact,
                'symbol' => $workspaceSymbol,
                'title' => mb_substr($title, 0, 500),
                'summary' => isset($row['summary']) ? mb_substr((string) $row['summary'], 0, 1500) : null,
                'source_url' => isset($row['url']) ? mb_substr((string) $row['url'], 0, 1000) : null,
                'source_id' => null,
                'occurred_at' => $scheduledAt,
                'raw_payload_json' => $row,
            ];
        }

        return $normalized;
    }

    private function parseTime(mixed $value): ?Carbon
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtoupper(trim($severity));

        return match ($severity) {
            ExternalContextTaxonomy::SEVERITY_CRITICAL,
            ExternalContextTaxonomy::SEVERITY_HIGH,
            ExternalContextTaxonomy::SEVERITY_MEDIUM,
            ExternalContextTaxonomy::SEVERITY_LOW => $severity,
            default => ExternalContextTaxonomy::SEVERITY_LOW,
        };
    }

    private function normalizeImpact(string $impact): string
    {
        $impact = strtoupper(trim($impact));

        return match ($impact) {
            ExternalContextTaxonomy::IMPACT_PAUSE_REVIEW,
            ExternalContextTaxonomy::IMPACT_NO_TRADE,
            ExternalContextTaxonomy::IMPACT_REDUCE_CONFIDENCE,
            ExternalContextTaxonomy::IMPACT_CAUTION,
            ExternalContextTaxonomy::IMPACT_IGNORE => $impact,
            default => ExternalContextTaxonomy::IMPACT_CAUTION,
        };
    }
}

