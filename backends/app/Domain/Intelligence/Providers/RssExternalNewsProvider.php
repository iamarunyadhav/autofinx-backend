<?php

namespace App\Domain\Intelligence\Providers;

use App\Domain\Intelligence\Contracts\ExternalNewsProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class RssExternalNewsProvider implements ExternalNewsProvider
{
    public function name(): string
    {
        return 'rss';
    }

    public function fetch(array $options = []): array
    {
        $urls = (array) ($options['rss_urls'] ?? []);
        $timeoutMs = (int) ($options['timeout_ms'] ?? 3500);
        $maxItems = (int) ($options['max_items'] ?? 40);
        if ($urls === []) {
            return [];
        }

        $items = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }

            try {
                $response = Http::timeout(max(1, (int) ceil($timeoutMs / 1000)))->get($url);
                if (! $response->ok()) {
                    continue;
                }

                $xml = $response->body();
                $parsed = @simplexml_load_string($xml);
                if (! $parsed) {
                    continue;
                }

                $channel = $parsed->channel ?? null;
                $entries = $channel?->item ?? $parsed->entry ?? [];

                foreach ($entries as $entry) {
                    $title = trim((string) ($entry->title ?? ''));
                    if ($title === '') {
                        continue;
                    }
                    $link = (string) ($entry->link ?? '');
                    if ($link === '' && isset($entry->link['href'])) {
                        $link = (string) $entry->link['href'];
                    }
                    $published = (string) ($entry->pubDate ?? $entry->published ?? $entry->updated ?? '');
                    $summary = trim((string) ($entry->description ?? $entry->summary ?? ''));

                    $items[] = [
                        'title' => mb_substr($title, 0, 500),
                        'summary' => $summary !== '' ? mb_substr($summary, 0, 1500) : null,
                        'url' => $link !== '' ? mb_substr($link, 0, 1000) : null,
                        'published_at' => $published !== '' ? $published : null,
                        'source_id' => null,
                    ];

                    if (count($items) >= $maxItems) {
                        break 2;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function healthReport(array $items, array $options = []): array
    {
        $maxFreshnessMinutes = max(1, (int) ($options['max_freshness_minutes'] ?? config('intelligence.external_context.news.max_freshness_minutes', 180)));
        $publishedTimes = [];

        foreach ($items as $item) {
            $publishedAt = trim((string) ($item['published_at'] ?? ''));
            if ($publishedAt === '') {
                continue;
            }

            try {
                $publishedTimes[] = Carbon::parse($publishedAt);
            } catch (\Throwable) {
                continue;
            }
        }

        if ($publishedTimes === []) {
            return [
                'status' => 'degraded',
                'alert' => 'rss_feed_missing_publish_times',
                'latest_published_at' => null,
                'newest_item_age_minutes' => null,
                'item_count' => count($items),
            ];
        }

        usort($publishedTimes, static fn (Carbon $a, Carbon $b) => $b->getTimestamp() <=> $a->getTimestamp());
        $latest = $publishedTimes[0];
        $ageMinutes = now()->diffInMinutes($latest);

        if ($ageMinutes > $maxFreshnessMinutes) {
            return [
                'status' => 'degraded',
                'alert' => 'rss_feed_stale',
                'latest_published_at' => $latest->toIso8601String(),
                'newest_item_age_minutes' => $ageMinutes,
                'item_count' => count($items),
            ];
        }

        return [
            'status' => 'ok',
            'alert' => null,
            'latest_published_at' => $latest->toIso8601String(),
            'newest_item_age_minutes' => $ageMinutes,
            'item_count' => count($items),
        ];
    }
}
