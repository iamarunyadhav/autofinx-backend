<?php

namespace Tests\Feature\MarketContext;

use App\Domain\MarketContext\Data\ContextQuery;
use App\Domain\MarketContext\MarketContextIngestionService;
use App\Domain\MarketContext\SnapshotBuilder;
use App\Domain\MarketContext\SnapshotStore;
use App\Models\MarketContextEvent;
use App\Models\MarketContextMetric;
use App\Models\MarketContextSnapshot;
use App\Models\MarketContextSourceHealth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Production runs APP_TIMEZONE=Asia/Colombo (+05:30) with a +08 host. Every
 * timestamp read/write and the as-of filter must still be exact UTC instants.
 */
class MarketContextTimezoneTest extends MarketContextTestCase
{
    private string $previousTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimezone = date_default_timezone_get();
        config(['app.timezone' => 'Asia/Colombo']);
        date_default_timezone_set('Asia/Colombo');
        config(['market_context.profiles.BTC.collectors' => ['fear_greed', 'rss_coindesk']]);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
        config(['app.timezone' => 'UTC']);
        parent::tearDown();
    }

    public function test_as_of_filter_is_exact_to_the_second_under_asia_colombo(): void
    {
        $this->event('BTC', '2026-10-06 03:00:00', title: 'Seen at exactly as_of');
        $this->event('BTC', '2026-10-06 03:00:01', title: 'Seen one second later');
        $builder = app(SnapshotBuilder::class);
        $build = fn (string $asOf) => array_column($builder->build(new ContextQuery('CRYPTO', 'BTC', Carbon::parse($asOf, 'UTC')))->toArray()['drivers'], 'title');

        $this->assertSame(['Seen at exactly as_of'], $build('2026-10-06 03:00:00'));
        $this->assertSame([], $build('2026-10-06 02:59:59'));
        $this->assertEqualsCanonicalizing(['Seen at exactly as_of', 'Seen one second later'], $build('2026-10-06 03:00:01'));

        // The same instant expressed in the app timezone must select identically (no +05:30 drift).
        $local = Carbon::parse('2026-10-06 08:30:00', 'Asia/Colombo');
        $this->assertSame(['Seen at exactly as_of'], array_column($builder->build(new ContextQuery('CRYPTO', 'BTC', $local))->toArray()['drivers'], 'title'));
    }

    public function test_timestamps_round_trip_as_utc_wall_time_and_true_instants(): void
    {
        $instant = Carbon::parse('2026-10-06 08:30:00', 'Asia/Colombo');   // 03:00:00 UTC
        $event = $this->event('BTC', '2026-10-06 03:00:00');
        $metric = MarketContextMetric::create(['source' => 'fear_greed', 'asset_class' => 'CRYPTO', 'instrument' => 'ALL', 'metric' => 'fear_greed',
            'value' => 55, 'observed_at' => $instant, 'first_seen_at' => $instant]);

        $this->assertSame('2026-10-06 03:00:00', $this->rawStored('mctx_events', $event->id, 'first_seen_at'));
        $this->assertSame('2026-10-06 03:00:00', $this->rawStored('mctx_metrics', $metric->id, 'observed_at'));
        $this->assertSame($instant->timestamp, MarketContextMetric::find($metric->id)->observed_at->timestamp);
        $this->assertSame($instant->timestamp, MarketContextEvent::find($event->id)->first_seen_at->timestamp);
        $this->assertSame('2026-10-06T03:00:00+00:00', MarketContextEvent::find($event->id)->first_seen_at->toIso8601String());
    }

    public function test_ingestion_writes_utc_even_though_now_is_colombo(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 08:30:00', 'Asia/Colombo'));
        Http::fake(['www.coindesk.com/*' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel><item><title>Bitcoin hacked</title>'
            .'<guid>g1</guid><pubDate>Tue, 06 Oct 2026 02:55:00 +0000</pubDate></item></channel></rss>', 200)]);

        app(MarketContextIngestionService::class)->run(['rss_coindesk']);

        $event = MarketContextEvent::firstOrFail();
        $this->assertSame('2026-10-06 03:00:00', $this->rawStored('mctx_events', $event->id, 'first_seen_at'));
        $this->assertSame('2026-10-06 02:55:00', $this->rawStored('mctx_events', $event->id, 'published_at'));
        $health = MarketContextSourceHealth::firstOrFail();
        $this->assertSame('2026-10-06 03:00:00', $this->rawStored('mctx_source_health', $health->id, 'last_success_at'));
        $this->assertSame('2026-10-06 03:15:00', $this->rawStored('mctx_source_health', $health->id, 'next_due_at'));
    }

    public function test_snapshot_store_buckets_and_round_trips_under_colombo(): void
    {
        $store = app(SnapshotStore::class);
        $bucket = $store->bucket(Carbon::parse('2026-10-06 08:37:12', 'Asia/Colombo'));   // 03:07:12 UTC
        $this->assertSame('2026-10-06T03:00:00+00:00', $bucket->toIso8601String());

        $snapshot = app(SnapshotBuilder::class)->build(new ContextQuery('CRYPTO', 'BTC', $bucket));
        $store->store($snapshot);
        $store->store($snapshot);   // same bucket: overwritten, not duplicated

        $row = $store->latest('CRYPTO', 'BTC');
        $this->assertSame(1, MarketContextSnapshot::count());
        $this->assertSame($bucket->timestamp, $row->as_of->timestamp);
        $this->assertSame('2026-10-06 03:00:00', $this->rawStored('mctx_snapshots', $row->id, 'as_of'));
        $this->assertSame('unavailable', $row->payload['freshness']['state']);
    }
}
