<?php

namespace App\Domain\Intelligence\Providers;

use App\Domain\Intelligence\Contracts\ExchangeStatusProvider;
use Illuminate\Support\Facades\Http;

class JsonExchangeStatusProvider implements ExchangeStatusProvider
{
    public function name(): string
    {
        return 'json';
    }

    public function fetch(array $options = []): array
    {
        $url = trim((string) ($options['url'] ?? ''));
        $timeoutMs = (int) ($options['timeout_ms'] ?? 3500);
        $maxItems = (int) ($options['max_items'] ?? 40);
        if ($url === '') {
            return [];
        }

        $response = Http::timeout(max(1, (int) ceil($timeoutMs / 1000)))->get($url);
        if (! $response->ok()) {
            return [];
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return [];
        }

        $items = [];
        foreach ($payload as $row) {
            if (! is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $items[] = [
                'title' => mb_substr($title, 0, 500),
                'summary' => isset($row['summary']) ? mb_substr((string) $row['summary'], 0, 1500) : null,
                'url' => isset($row['url']) ? mb_substr((string) $row['url'], 0, 1000) : null,
                'occurred_at' => $row['occurred_at'] ?? null,
                'severity' => $row['severity'] ?? null,
                'impact' => $row['impact'] ?? null,
            ];
            if (count($items) >= $maxItems) {
                break;
            }
        }

        return $items;
    }
}

