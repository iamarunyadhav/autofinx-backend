<?php

namespace App\Domain\Intelligence;

use Illuminate\Support\Carbon;

class NewsSignalIngestionService
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

            $publishedAt = $this->parseTime($row['published_at'] ?? null);

            $normalized[] = [
                'provider' => $providerName,
                'category' => ExternalContextTaxonomy::CATEGORY_CRYPTO_MARKET_NEWS,
                'severity' => ExternalContextTaxonomy::SEVERITY_LOW,
                'impact_flag' => ExternalContextTaxonomy::IMPACT_IGNORE,
                'symbol' => $workspaceSymbol,
                'title' => mb_substr($title, 0, 500),
                'summary' => isset($row['summary']) ? mb_substr((string) $row['summary'], 0, 1500) : null,
                'source_url' => isset($row['url']) ? mb_substr((string) $row['url'], 0, 1000) : null,
                'source_id' => isset($row['source_id']) ? mb_substr((string) $row['source_id'], 0, 200) : null,
                'occurred_at' => $publishedAt,
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
}

