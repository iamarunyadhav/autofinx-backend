<?php

namespace App\Domain\Intelligence;

use App\Domain\Intelligence\Contracts\ExchangeStatusProvider;
use App\Domain\Intelligence\Contracts\ExternalNewsProvider;
use App\Domain\Intelligence\Contracts\MacroCalendarProvider;
use App\Domain\Intelligence\Providers\JsonExchangeStatusProvider;
use App\Domain\Intelligence\Providers\JsonMacroCalendarProvider;
use App\Domain\Intelligence\Providers\NullExchangeStatusProvider;
use App\Domain\Intelligence\Providers\NullExternalNewsProvider;
use App\Domain\Intelligence\Providers\NullMacroCalendarProvider;
use App\Domain\Intelligence\Providers\RssExternalNewsProvider;
use App\Models\ExternalContextEvent;
use App\Models\ExternalContextSnapshot;
use App\Models\ExternalContextSourceHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ExternalContextAggregatorService
{
    public function __construct(
        private readonly NewsSignalIngestionService $news,
        private readonly MacroEventContextService $macro,
        private readonly ExchangeEventContextService $exchange,
        private readonly ExternalContextScoringService $scoring,
        private readonly ExternalContextSummaryService $summary,
    ) {
    }

    public function snapshotPacket(string $workspaceId, string $symbol, array $aiTradingConfig, array $options = []): array
    {
        $symbol = strtoupper(trim($symbol));
        $use = (bool) ($aiTradingConfig['use_external_context'] ?? config('intelligence.external_context.enabled_by_default', false));
        if (! $use) {
            return [
                'snapshot_id' => null,
                'external_context' => [
                    'available' => false,
                    'freshness_state' => 'disabled',
                    'source_health' => [],
                ],
            ];
        }

        $maxAgeMinutes = (int) ($aiTradingConfig['max_external_context_age_minutes'] ?? config('intelligence.external_context.max_age_minutes', 60));
        $force = (bool) ($options['force'] ?? false);

        $latest = ExternalContextSnapshot::query()
            ->where('workspace_id', $workspaceId)
            ->where('symbol', $symbol)
            ->orderByDesc('computed_at')
            ->first();

        if (! $force && $latest && $latest->computed_at && $latest->computed_at->gte(now()->subMinutes(max(1, $maxAgeMinutes)))) {
            return [
                'snapshot_id' => $latest->id,
                'external_context' => array_merge(
                    $latest->summary_json ?? [],
                    [
                        'available' => true,
                        'freshness_state' => (string) ($latest->freshness_state ?: 'fresh'),
                        'source_health' => $latest->source_health_json ?? [],
                    ]
                ),
            ];
        }

        $now = now();
        $timeoutMs = (int) config('intelligence.external_context.timeout_ms', 3500);
        $maxItems = (int) config('intelligence.external_context.max_items', 40);

        $providerHealth = [];
        $events = [];

        $newsProvider = $this->newsProvider();
        $macroProvider = $this->macroProvider();
        $exchangeProvider = $this->exchangeProvider();

        [$newsItems, $providerHealth] = $this->safeFetch($workspaceId, $newsProvider, $providerHealth, [
            'timeout_ms' => $timeoutMs,
            'max_items' => $maxItems,
            'rss_urls' => (array) config('intelligence.external_context.news.rss_urls', []),
            'max_freshness_minutes' => (int) config('intelligence.external_context.news.max_freshness_minutes', 180),
        ]);
        $events = array_merge($events, $this->news->normalize($newsItems, $newsProvider->name(), null));

        [$macroItems, $providerHealth] = $this->safeFetch($workspaceId, $macroProvider, $providerHealth, [
            'timeout_ms' => $timeoutMs,
            'max_items' => $maxItems,
            'url' => (string) config('intelligence.external_context.macro.json_url', ''),
        ]);
        $events = array_merge($events, $this->macro->normalize($macroItems, $macroProvider->name(), null));

        [$exchangeItems, $providerHealth] = $this->safeFetch($workspaceId, $exchangeProvider, $providerHealth, [
            'timeout_ms' => $timeoutMs,
            'max_items' => $maxItems,
            'url' => (string) config('intelligence.external_context.exchange.json_url', ''),
        ]);
        $events = array_merge($events, $this->exchange->normalize($exchangeItems, $exchangeProvider->name(), null));

        $normalizedEvents = $this->persistEvents($workspaceId, $events, $now, (bool) ($aiTradingConfig['ignore_low_severity_news'] ?? true));
        $score = $this->scoring->score($normalizedEvents, $now, max(1, $maxAgeMinutes));
        $summary = $this->summary->summarize($normalizedEvents, $score, $now, max(1, $maxAgeMinutes));

        $snapshot = ExternalContextSnapshot::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'symbol' => $symbol,
            'status' => $this->snapshotStatus($providerHealth, $summary),
            'age_minutes' => 0,
            'freshness_state' => 'fresh',
            'recommended_bias' => (string) ($summary['recommended_bias'] ?? 'neutral'),
            'confidence_adjustment_hint' => (float) ($summary['confidence_adjustment_hint'] ?? 0),
            'no_trade_hint' => (bool) ($summary['no_trade_hint'] ?? false),
            'pause_review_hint' => (bool) ($summary['pause_review_hint'] ?? false),
            'event_flags' => $summary['event_flags'] ?? [],
            'high_severity_events' => $summary['high_severity_events'] ?? [],
            'summary_json' => $summary,
            'source_health_json' => $providerHealth,
            'computed_at' => $now,
        ]);

        return [
            'snapshot_id' => $snapshot->id,
            'external_context' => array_merge($summary, [
                'available' => true,
                'freshness_state' => 'fresh',
                'source_health' => $providerHealth,
            ]),
        ];
    }

    private function snapshotStatus(array $providerHealth, array $summary): string
    {
        $anyDown = false;
        foreach ($providerHealth as $health) {
            if (is_array($health) && ($health['status'] ?? '') === 'down') {
                $anyDown = true;
                break;
            }
        }

        if ($anyDown && (($summary['news_summary'] ?? []) === []) && (($summary['macro_summary'] ?? []) === []) && (($summary['exchange_summary'] ?? []) === [])) {
            return 'unavailable';
        }

        return $anyDown ? 'partial' : 'available';
    }

    private function newsProvider(): ExternalNewsProvider
    {
        $provider = strtolower((string) config('intelligence.external_context.news.provider', 'rss'));

        return match ($provider) {
            'null', 'off', 'disabled' => new NullExternalNewsProvider(),
            default => new RssExternalNewsProvider(),
        };
    }

    private function macroProvider(): MacroCalendarProvider
    {
        $provider = strtolower((string) config('intelligence.external_context.macro.provider', 'null'));

        return match ($provider) {
            'json' => new JsonMacroCalendarProvider(),
            default => new NullMacroCalendarProvider(),
        };
    }

    private function exchangeProvider(): ExchangeStatusProvider
    {
        $provider = strtolower((string) config('intelligence.external_context.exchange.provider', 'null'));

        return match ($provider) {
            'json' => new JsonExchangeStatusProvider(),
            default => new NullExchangeStatusProvider(),
        };
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function safeFetch(string $workspaceId, object $provider, array $healthState, array $options): array
    {
        $name = method_exists($provider, 'name') ? (string) $provider->name() : 'unknown';
        $providerKey = $this->providerKey($provider, $name);

        try {
            $items = (array) $provider->fetch($options);
            $health = $this->providerHealthPayload($provider, $items, $options);
            $this->recordHealthState($workspaceId, $providerKey, $health);
            $healthState[$providerKey] = $health;

            return [$items, $healthState];
        } catch (\Throwable $e) {
            $health = [
                'status' => 'down',
                'last_error_at' => now()->toIso8601String(),
                'last_error_message' => $e->getMessage(),
            ];
            $this->recordHealthState($workspaceId, $providerKey, $health);
            $healthState[$providerKey] = $health;

            return [[], $healthState];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function providerHealthPayload(object $provider, array $items, array $options): array
    {
        $base = [
            'status' => 'ok',
            'last_ok_at' => now()->toIso8601String(),
            'item_count' => count($items),
        ];

        if (method_exists($provider, 'healthReport')) {
            $details = (array) $provider->healthReport($items, $options);
            $status = strtolower((string) ($details['status'] ?? 'ok'));

            return array_merge($base, $details, [
                'status' => in_array($status, ['ok', 'degraded', 'down'], true) ? $status : 'ok',
            ]);
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $health
     */
    private function recordHealthState(string $workspaceId, string $providerKey, array $health): void
    {
        $row = ExternalContextSourceHealth::where('workspace_id', $workspaceId)->where('provider', $providerKey)->first();
        $status = strtolower((string) ($health['status'] ?? 'unknown'));
        $isFailure = $status === 'down';
        $failures = $isFailure ? ((int) ($row?->consecutive_failures ?? 0) + 1) : 0;
        $lastOkAt = in_array($status, ['ok', 'degraded'], true) ? now() : $row?->last_ok_at;
        $lastErrorAt = $isFailure ? now() : null;
        $lastErrorMessage = $isFailure ? mb_substr((string) ($health['last_error_message'] ?? 'provider_down'), 0, 2000) : null;
        $meta = $health;
        unset($meta['status'], $meta['last_ok_at'], $meta['last_error_at'], $meta['last_error_message']);

        ExternalContextSourceHealth::updateOrCreate(
            ['workspace_id' => $workspaceId, 'provider' => $providerKey],
            [
                'id' => $row?->id ?? (string) Str::uuid(),
                'status' => $status,
                'consecutive_failures' => $failures,
                'last_ok_at' => $lastOkAt,
                'last_error_at' => $lastErrorAt,
                'last_error_code' => $isFailure ? (string) ($health['last_error_code'] ?? null) : null,
                'last_error_message' => $lastErrorMessage,
                'meta_json' => $meta !== [] ? $meta : null,
            ]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function persistEvents(string $workspaceId, array $events, Carbon $now, bool $ignoreLowNews): array
    {
        $persisted = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            if ($ignoreLowNews
                && ($event['category'] ?? null) === ExternalContextTaxonomy::CATEGORY_CRYPTO_MARKET_NEWS
                && strtoupper((string) ($event['severity'] ?? '')) === ExternalContextTaxonomy::SEVERITY_LOW
            ) {
                continue;
            }

            $dedupe = $this->dedupeKey($event);
            $row = ExternalContextEvent::updateOrCreate(
                ['dedupe_key' => $dedupe],
                [
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspaceId,
                    'provider' => (string) ($event['provider'] ?? 'unknown'),
                    'category' => (string) ($event['category'] ?? ExternalContextTaxonomy::CATEGORY_CRYPTO_MARKET_NEWS),
                    'severity' => (string) ($event['severity'] ?? ExternalContextTaxonomy::SEVERITY_LOW),
                    'impact_flag' => (string) ($event['impact_flag'] ?? ExternalContextTaxonomy::IMPACT_IGNORE),
                    'symbol' => $event['symbol'] ?? null,
                    'title' => (string) ($event['title'] ?? ''),
                    'summary' => $event['summary'] ?? null,
                    'source_url' => $event['source_url'] ?? null,
                    'source_id' => $event['source_id'] ?? null,
                    'occurred_at' => $event['occurred_at'] ?? null,
                    'fetched_at' => $now,
                    'raw_payload_json' => $event['raw_payload_json'] ?? null,
                    'normalized_payload_json' => $event,
                ]
            );

            $persisted[] = array_merge($event, [
                'id' => $row->id,
                'occurred_at' => $event['occurred_at'] instanceof Carbon ? $event['occurred_at'] : null,
                'source_url' => $event['source_url'] ?? null,
            ]);
        }

        return $persisted;
    }

    private function dedupeKey(array $event): string
    {
        $provider = strtolower((string) ($event['provider'] ?? 'unknown'));
        $category = strtolower((string) ($event['category'] ?? 'unknown'));
        $title = strtolower(trim((string) ($event['title'] ?? '')));
        $sourceId = strtolower(trim((string) ($event['source_id'] ?? '')));
        $occurred = $event['occurred_at'] instanceof Carbon ? $event['occurred_at']->toDateString() : '';

        $hash = sha1($provider . '|' . $category . '|' . $title . '|' . $sourceId . '|' . $occurred);

        return mb_substr($provider . ':' . $hash, 0, 200);
    }

    private function providerKey(object $provider, string $name): string
    {
        $shortType = match (true) {
            $provider instanceof ExternalNewsProvider => 'news',
            $provider instanceof MacroCalendarProvider => 'macro',
            $provider instanceof ExchangeStatusProvider => 'exchange',
            default => 'provider',
        };

        $shortName = strtolower(trim($name));
        $shortName = preg_replace('/[^a-z0-9_\-]+/i', '', $shortName) ?: 'default';

        return mb_substr($shortType . ':' . $shortName, 0, 64);
    }
}
