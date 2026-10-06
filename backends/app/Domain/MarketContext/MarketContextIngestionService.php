<?php

namespace App\Domain\MarketContext;

use App\Domain\Intelligence\ExternalContextTaxonomy as Tax;
use App\Domain\MarketContext\Classification\RuleClassifier;
use App\Domain\MarketContext\Collectors\FearGreedCollector;
use App\Domain\MarketContext\Collectors\SourceFetchException;
use App\Domain\MarketContext\Contracts\ContextCollector;
use App\Domain\MarketContext\Data\CollectResult;
use App\Domain\MarketContext\Support\ProfileRegistry;
use App\Domain\MarketContext\Support\UtcTime;
use App\Models\MarketContextEvent;
use App\Models\MarketContextEventInstrument;
use App\Models\MarketContextMetric;
use App\Models\MarketContextSourceHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Collect -> normalize -> dedupe -> store. Global public data. Every source is
 * isolated: one failing source records its own health and never stops the rest.
 * Memory is bounded by the collectors' item caps and the 256 KiB body cap.
 */
class MarketContextIngestionService
{
    public function __construct(
        private readonly RuleClassifier $classifier,
        private readonly ProfileRegistry $profiles,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('market_context.enabled', false);
    }

    /**
     * @param  list<string>  $only  restrict to these source ids (empty = every enabled source)
     * @return array<string, array> source id => summary
     */
    public function run(array $only = [], bool $force = false, ?Carbon $now = null): array
    {
        if (! self::enabled()) {
            return [];
        }
        $now = ($now ?? now())->copy()->utc();
        $out = [];
        foreach ((array) config('market_context.sources', []) as $id => $source) {
            if ($only !== [] && ! in_array($id, $only, true)) {
                continue;
            }
            if (! ($source['enabled'] ?? false)) {
                $out[$id] = ['status' => 'disabled'];

                continue;
            }
            $out[$id] = $this->runSource($id, $source, $force, $now);
        }

        return $out;
    }

    /** One-off Fear & Greed history load. Idempotent: existing (source, metric, observed_at) rows are kept. */
    public function backfillFearGreed(int $days, ?Carbon $now = null): array
    {
        if (! self::enabled()) {
            return ['status' => 'disabled'];
        }
        $now = ($now ?? now())->copy()->utc();
        /** @var FearGreedCollector $collector */
        $collector = $this->makeCollector('fear_greed', (array) config('market_context.sources.fear_greed'));
        $result = $collector->backfill($days, $now);

        return ['status' => 'ok'] + $this->store('fear_greed', $result, $now);
    }

    private function runSource(string $id, array $source, bool $force, Carbon $now): array
    {
        $health = MarketContextSourceHealth::query()->firstOrCreate(['source' => $id], ['status' => 'NEW', 'consecutive_failures' => 0]);
        if ($health->disabled_until && $health->disabled_until->greaterThan($now)) {
            return ['status' => 'breaker_open', 'until' => $health->disabled_until->toIso8601String()];
        }
        if (! $force && $health->next_due_at && $health->next_due_at->greaterThan($now)) {
            return ['status' => 'not_due'];
        }
        $lock = Cache::lock('market_context:collect:'.$id, (int) config('market_context.breaker.lock_seconds', 120));
        if (! $lock->get()) {
            return ['status' => 'busy'];
        }

        try {
            $health->last_attempt_at = $now;
            try {
                $result = $this->makeCollector($id, $source)->collect($health->etag, $health->last_modified, $now);
                $stored = $result->notModified ? ['events' => 0, 'metrics' => 0] : $this->store($id, $result, $now);
            } catch (Throwable $e) {
                return $this->recordFailure($health, $id, $e, $now);
            }

            $health->status = $result->failedParts > 0 ? 'DEGRADED' : 'OK';
            $health->last_success_at = $now;
            $health->consecutive_failures = 0;
            $health->disabled_until = null;
            $health->last_error_code = $result->failedParts > 0 ? 'PARTIAL' : null;
            $health->next_due_at = $now->copy()->addMinutes(max(1, (int) ($source['refresh_minutes'] ?? 60)));
            if (! $result->notModified) {
                $health->etag = $result->etag;
                $health->last_modified = $result->lastModified;
                $health->last_content_hash = $result->contentHash;
                $health->last_item_count = count($result->items) + count($result->metrics);
            }
            $health->save();

            return ['status' => $result->notModified ? 'not_modified' : $health->status] + $stored + ['failed_parts' => $result->failedParts];
        } finally {
            $lock->release();
        }
    }

    private function recordFailure(MarketContextSourceHealth $health, string $id, Throwable $e, Carbon $now): array
    {
        // Short code only: never an upstream body, URL or credential.
        $code = $e instanceof SourceFetchException ? $e->errorCode : 'FETCH_FAILED';
        $failures = (int) $health->consecutive_failures + 1;
        $threshold = max(1, (int) config('market_context.breaker.failure_threshold', 5));
        $health->consecutive_failures = $failures;
        $health->last_error_code = $code;
        $health->status = $failures >= $threshold ? 'OPEN' : 'FAILING';
        $health->next_due_at = $now->copy()->addMinutes(max(1, (int) config('market_context.breaker.backoff_minutes', 10)) * min($failures, 6));
        if ($failures >= $threshold) {
            $health->disabled_until = $now->copy()->addMinutes(max(1, (int) config('market_context.breaker.cooldown_minutes', 60)));
        }
        $health->save();
        Log::warning('market_context.source_failed', ['source' => $id, 'code' => $code, 'failures' => $failures]);

        return ['status' => strtolower($health->status), 'error' => $code];
    }

    public function makeCollector(string $id, array $source): ContextCollector
    {
        $class = $source['collector'] ?? null;
        if (! is_string($class) || ! is_subclass_of($class, ContextCollector::class)) {
            throw new SourceFetchException('BAD_CONFIG');
        }

        return app()->make($class, ['source' => $id, 'config' => $source]);
    }

    /** @return array{events:int, metrics:int} newly stored rows (duplicates are skipped) */
    public function store(string $sourceId, CollectResult $result, Carbon $now): array
    {
        return ['events' => $this->storeEvents($sourceId, $result->items, $now), 'metrics' => $this->storeMetrics($result->metrics, $now)];
    }

    private function storeEvents(string $sourceId, array $items, Carbon $now): int
    {
        if ($items === []) {
            return 0;
        }
        $source = (array) config('market_context.sources.'.$sourceId, []);
        $seen = UtcTime::db($now);
        $byKey = [];
        foreach ($items as $item) {
            $key = hash('sha256', $item->source.'|'.($item->externalId ?? Str::lower(preg_replace('/\s+/u', ' ', $item->title))));
            $byKey[$key] ??= $item;
        }
        $existing = MarketContextEvent::query()->whereIn('dedupe_key', array_keys($byKey))->pluck('dedupe_key')->all();
        $maxExcerpt = (int) config('market_context.limits.max_excerpt_chars', 300);

        $rows = [];
        $plans = [];
        foreach (array_diff_key($byKey, array_flip($existing)) as $key => $item) {
            $class = $this->classifier->classify($item, $source);
            // A source cannot publish after we first saw it: clamp so as-of math never leaks the future.
            $published = UtcTime::db($item->publishedAt->greaterThan($now) ? $now : $item->publishedAt);
            $id = (string) Str::uuid7();
            $excerpt = $item->excerpt !== null ? mb_substr($item->excerpt, 0, $maxExcerpt) : null;
            $rows[] = ['id' => $id, 'dedupe_key' => $key, 'source' => $item->source, 'external_id' => $item->externalId,
                'title' => mb_substr($item->title, 0, 300), 'excerpt' => $excerpt, 'url' => $item->url,
                'category' => $class['category'], 'severity' => $class['severity'], 'classifier_version' => $class['version'],
                'published_at' => $published, 'first_seen_at' => $seen,
                'raw_hash' => hash('sha256', $item->title.'|'.($item->excerpt ?? '')), 'created_at' => $seen, 'updated_at' => $seen];
            $plans[$id] = [$item, $class['severity'], $published];
        }
        if ($rows === []) {
            return 0;
        }

        $inserted = 0;
        foreach (array_chunk($rows, 100) as $chunk) {
            $inserted += (int) MarketContextEvent::query()->insertOrIgnore($chunk);
        }
        // Link only events that really are ours (a racing writer may have won the unique key).
        $stored = MarketContextEvent::query()->whereIn('id', array_keys($plans))->pluck('id')->all();
        $links = [];
        foreach ($stored as $id) {
            [$item, $severity, $published] = $plans[$id];
            foreach ($this->relevance($sourceId, $source, $item->title.' '.($item->excerpt ?? ''), $severity) as $instrument => [$assetClass, $relevance]) {
                $links[] = ['id' => (string) Str::uuid7(), 'event_id' => $id, 'asset_class' => $assetClass, 'instrument' => $instrument,
                    'relevance' => $relevance, 'published_at' => $published, 'first_seen_at' => $seen, 'created_at' => $seen, 'updated_at' => $seen];
            }
        }
        foreach (array_chunk($links, 200) as $chunk) {
            MarketContextEventInstrument::query()->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    /**
     * Which instruments an event concerns. A name/ticker match is 1.0. Market-wide
     * sources (central banks, exchange notices) reach every applicable instrument at 0.5.
     * Unmatched sector news only reaches them (0.25) when it is MEDIUM severity or worse.
     *
     * @return array<string, array{0:string,1:float}> instrument => [asset class, relevance]
     */
    private function relevance(string $sourceId, array $source, string $text, string $severity): array
    {
        $out = [];
        $applicable = $this->profiles->usingCollector($sourceId);
        $named = [];
        foreach ($applicable as $instrument => $profile) {
            if ($this->profiles->matches($profile, $text)) {
                $named[$instrument] = [$profile['asset_class'], 1.0];
            }
        }
        $broad = ($source['scope'] ?? 'asset_specific') === 'market_wide' ? 0.5
            : (Tax::severityRank($severity) >= Tax::severityRank(Tax::SEVERITY_MEDIUM) ? 0.25 : 0.0);
        foreach ($applicable as $instrument => $profile) {
            if (isset($named[$instrument])) {
                $out[$instrument] = $named[$instrument];
            } elseif ($broad > 0 && ($named === [] || ($source['scope'] ?? '') === 'market_wide')) {
                $out[$instrument] = [$profile['asset_class'], $broad];
            }
        }

        return $out;
    }

    private function storeMetrics(array $metrics, Carbon $now): int
    {
        $seenNow = UtcTime::db($now);
        $rows = [];
        foreach ($metrics as $m) {
            $observed = UtcTime::db($m->observedAt);
            $key = $m->source.'|'.$m->instrument.'|'.$m->metric.'|'.$observed;
            $rows[$key] = ['id' => (string) Str::uuid7(), 'source' => $m->source, 'asset_class' => $m->assetClass,
                'instrument' => $m->instrument, 'metric' => $m->metric, 'value' => number_format($m->value, 8, '.', ''), 'observed_at' => $observed,
                'first_seen_at' => $m->firstSeenAt ? UtcTime::db($m->firstSeenAt) : $seenNow, 'created_at' => $seenNow, 'updated_at' => $seenNow];
        }
        $inserted = 0;
        // insertOrIgnore on the unique (source, instrument, metric, observed_at): a value, once seen, is never rewritten.
        foreach (array_chunk(array_values($rows), 200) as $chunk) {
            $inserted += (int) MarketContextMetric::query()->insertOrIgnore($chunk);
        }

        return $inserted;
    }
}
