<?php

namespace Tests\Feature\MarketContext;

use App\Domain\MarketContext\Support\UtcTime;
use App\Models\MarketContextEvent;
use App\Models\MarketContextEventInstrument;
use App\Models\MarketContextMetric;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class MarketContextTestCase extends TestCase
{
    /** Fixed "now" used by every test: 2026-10-06 03:30 UTC (matches the recorded fixtures). */
    protected Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        config(['market_context.enabled' => true, 'market_context.http.pin_dns' => false,
            'future_trade.binance_rest.min_interval_ms' => 0]);
        $this->now = Carbon::parse('2026-10-06 03:30:00', 'UTC');
        $this->travelTo($this->now);
    }

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/MarketContext/'.$name));
    }

    /** Insert an event + instrument link directly (bypassing ingestion) with explicit UTC instants. */
    protected function event(string $instrument, string $firstSeen, ?string $published = null, string $severity = 'HIGH',
        string $category = 'SECURITY_INCIDENT', float $relevance = 1.0, string $source = 'rss_coindesk', ?string $title = null): MarketContextEvent
    {
        $published ??= $firstSeen;
        $event = MarketContextEvent::create(['dedupe_key' => hash('sha256', Str::uuid()), 'source' => $source, 'external_id' => null,
            'title' => $title ?? 'Test event '.Str::random(6), 'excerpt' => null, 'url' => 'https://example.test/x', 'category' => $category,
            'severity' => $severity, 'classifier_version' => 'rules-1', 'published_at' => Carbon::parse($published, 'UTC'),
            'first_seen_at' => Carbon::parse($firstSeen, 'UTC'), 'raw_hash' => str_repeat('a', 64)]);
        MarketContextEventInstrument::create(['event_id' => $event->id, 'asset_class' => 'CRYPTO', 'instrument' => $instrument,
            'relevance' => $relevance, 'published_at' => Carbon::parse($published, 'UTC'), 'first_seen_at' => Carbon::parse($firstSeen, 'UTC')]);

        return $event;
    }

    protected function metric(string $instrument, string $metric, float $value, string $observed, ?string $firstSeen = null, string $source = 'fear_greed'): MarketContextMetric
    {
        return MarketContextMetric::create(['source' => $source, 'asset_class' => 'CRYPTO', 'instrument' => $instrument, 'metric' => $metric,
            'value' => $value, 'observed_at' => Carbon::parse($observed, 'UTC'), 'first_seen_at' => Carbon::parse($firstSeen ?? $observed, 'UTC')]);
    }

    /** Raw wall-time string as stored (to prove UTC storage irrespective of app timezone). */
    protected function rawStored(string $table, string $id, string $column): string
    {
        return (string) DB::table($table)->where('id', $id)->value($column);
    }

    protected function utc(Carbon $c): string
    {
        return UtcTime::db($c);
    }
}
