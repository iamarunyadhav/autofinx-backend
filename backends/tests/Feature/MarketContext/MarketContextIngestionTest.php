<?php

namespace Tests\Feature\MarketContext;

use App\Domain\MarketContext\MarketContextIngestionService;
use App\Models\MarketContextEvent;
use App\Models\MarketContextEventInstrument;
use App\Models\MarketContextMetric;
use App\Models\MarketContextSourceHealth;
use Illuminate\Support\Facades\Http;

class MarketContextIngestionTest extends MarketContextTestCase
{
    private function ingestion(): MarketContextIngestionService
    {
        return app(MarketContextIngestionService::class);
    }

    private function feed(array $titles): string
    {
        $items = '';
        foreach ($titles as $i => $title) {
            $items .= '<item><title>'.$title.'</title><link>https://example.test/a'.$i.'</link><guid>g'.$i.'</guid>'
                .'<pubDate>Tue, 06 Oct 2026 03:0'.$i.':00 +0000</pubDate></item>';
        }

        return '<?xml version="1.0"?><rss version="2.0"><channel>'.$items.'</channel></rss>';
    }

    public function test_module_does_nothing_and_costs_nothing_when_the_flag_is_off(): void
    {
        config(['market_context.enabled' => false]);
        Http::fake();

        $this->assertSame([], $this->ingestion()->run());
        $this->assertSame(['status' => 'disabled'], $this->ingestion()->backfillFearGreed(30));
        $this->artisan('market-context:collect')->expectsOutputToContain('disabled')->assertSuccessful();
        $this->artisan('market-context:snapshot')->expectsOutputToContain('disabled')->assertSuccessful();
        $this->artisan('market-context:prune')->expectsOutputToContain('disabled')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, MarketContextEvent::count() + MarketContextMetric::count() + MarketContextSourceHealth::count());
        $this->assertSame(0, \App\Models\MarketContextSnapshot::count());
        $this->assertSame(['status' => 'DISABLED'], app(\App\Domain\MarketContext\MarketContextObservation::class)->summary());
    }

    public function test_nothing_is_scheduled_while_the_flag_is_off(): void
    {
        // routes/console.php is evaluated at boot with the flag OFF (phpunit default): no market-context entries.
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($e) => $e->command)->implode("\n");
        $this->assertStringNotContainsString('market-context', $commands);
    }

    public function test_collect_stores_events_and_metrics_and_a_rerun_is_idempotent(): void
    {
        Http::fake([
            'www.coindesk.com/*' => Http::response($this->feed(['Bitcoin ETF inflows rise', 'Quiet day in markets']), 200, ['ETag' => '"v1"']),
            'api.alternative.me/*' => Http::response($this->fixture('fng.json'), 200),
        ]);

        $first = $this->ingestion()->run(['rss_coindesk', 'fear_greed']);
        $this->assertSame('ok', strtolower($first['rss_coindesk']['status']));
        $this->assertSame(2, $first['rss_coindesk']['events']);
        $this->assertSame(3, $first['fear_greed']['metrics']);

        $second = $this->ingestion()->run(['rss_coindesk', 'fear_greed'], force: true);
        $this->assertSame(0, $second['rss_coindesk']['events']);
        $this->assertSame(0, $second['fear_greed']['metrics']);
        $this->assertSame(2, MarketContextEvent::count());
        $this->assertSame(3, MarketContextMetric::count());

        $event = MarketContextEvent::where('title', 'Bitcoin ETF inflows rise')->firstOrFail();
        $this->assertSame('rules-1', $event->classifier_version);
        $this->assertSame($this->now->timestamp, $event->first_seen_at->timestamp);
        $this->assertSame('2026-10-06 03:00:00', $this->rawStored('mctx_events', $event->id, 'published_at'));
    }

    public function test_events_are_linked_to_instruments_by_alias_and_severity(): void
    {
        Http::fake(['www.coindesk.com/*' => Http::response($this->feed([
            'Bitcoin ETF inflows rise',                        // names BTC only
            'Exchange hacked and funds drained overnight',     // HIGH incident, no alias: reaches all at 0.25
            'Quiet day in markets',                            // LOW, no alias: linked to nothing
        ]), 200)]);

        $this->ingestion()->run(['rss_coindesk']);

        $links = fn (string $title) => MarketContextEventInstrument::whereIn('event_id', MarketContextEvent::where('title', $title)->pluck('id'))
            ->get()->mapWithKeys(fn ($l) => [$l->instrument => $l->relevance]);
        $this->assertSame(['BTC' => 1.0], $links('Bitcoin ETF inflows rise')->all());
        $this->assertCount(6, $links('Exchange hacked and funds drained overnight'));
        $this->assertSame([0.25], $links('Exchange hacked and funds drained overnight')->unique()->values()->all());
        $this->assertCount(0, $links('Quiet day in markets'));
        $hack = MarketContextEvent::where('title', 'like', 'Exchange hacked%')->first();
        $this->assertSame(['SECURITY_INCIDENT', 'HIGH'], [$hack->category, $hack->severity]);
        $this->assertNotContains('XAU', MarketContextEventInstrument::pluck('instrument')->all(), 'disabled profiles never receive links');
    }

    public function test_central_bank_events_reach_every_applicable_instrument_at_half_relevance(): void
    {
        Http::fake(['www.federalreserve.gov/*' => Http::response($this->fixture('rss_fed.xml'), 200)]);

        $this->ingestion()->run(['rss_fed']);

        $this->assertSame(3, MarketContextEvent::count());
        $this->assertSame(18, MarketContextEventInstrument::count()); // 3 events x 6 enabled crypto profiles
        $this->assertSame([0.5], MarketContextEventInstrument::pluck('relevance')->unique()->values()->all());
    }

    public function test_failure_records_health_without_leaking_the_upstream_body_and_opens_the_breaker(): void
    {
        config(['market_context.breaker.failure_threshold' => 3]);
        Http::fake(['www.coindesk.com/*' => Http::response('secret-token-123 internal stack trace', 500)]);

        for ($i = 0; $i < 3; $i++) {
            $out = $this->ingestion()->run(['rss_coindesk'], force: true);
        }
        $health = MarketContextSourceHealth::where('source', 'rss_coindesk')->firstOrFail();
        $this->assertSame('OPEN', $health->status);
        $this->assertSame(3, $health->consecutive_failures);
        $this->assertSame('HTTP_500', $health->last_error_code);
        $this->assertStringNotContainsString('secret', json_encode($out).json_encode($health->toArray()));
        $this->assertTrue($health->disabled_until->greaterThan($this->now));

        // While the breaker is open the source is not contacted, even with --force.
        $this->assertSame('breaker_open', $this->ingestion()->run(['rss_coindesk'], force: true)['rss_coindesk']['status']);
        Http::assertSentCount(3);   // only the three failing attempts; the breaker-open run sent nothing

        // After the cooldown it is retried and a success resets the counter.
        $this->travelTo($this->now->copy()->addMinutes(61));
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['www.coindesk.com/*' => Http::response($this->feed(['Bitcoin recovers']), 200)]);
        $this->ingestion()->run(['rss_coindesk'], force: true);
        $health->refresh();
        $this->assertSame(['OK', 0, null], [$health->status, $health->consecutive_failures, $health->disabled_until]);
    }

    public function test_one_failing_source_does_not_stop_the_others(): void
    {
        Http::fake([
            'www.coindesk.com/*' => Http::response('boom', 503),
            'cointelegraph.com/*' => Http::response($this->fixture('rss_cointelegraph.xml'), 200),
        ]);

        $out = $this->ingestion()->run(['rss_coindesk', 'rss_cointelegraph']);

        $this->assertSame('failing', $out['rss_coindesk']['status']);
        $this->assertSame(2, $out['rss_cointelegraph']['events']);
    }

    public function test_refresh_interval_and_conditional_get_are_honoured(): void
    {
        Http::fake(['www.coindesk.com/*' => Http::sequence()
            ->push($this->feed(['Bitcoin recovers']), 200, ['ETag' => '"v1"', 'Last-Modified' => 'Tue, 06 Oct 2026 03:00:00 GMT'])
            ->push('', 304)]);

        $this->ingestion()->run(['rss_coindesk']);
        $this->assertSame('not_due', $this->ingestion()->run(['rss_coindesk'])['rss_coindesk']['status']);
        Http::assertSentCount(1);

        $this->travelTo($this->now->copy()->addMinutes(16));
        $out = $this->ingestion()->run(['rss_coindesk']);
        $this->assertSame('not_modified', $out['rss_coindesk']['status']);
        Http::assertSent(fn ($r) => $r->hasHeader('If-None-Match', '"v1"') && $r->hasHeader('If-Modified-Since', 'Tue, 06 Oct 2026 03:00:00 GMT'));
        $this->assertSame(1, MarketContextEvent::count());
        $this->assertSame('"v1"', MarketContextSourceHealth::where('source', 'rss_coindesk')->value('etag'), 'validators survive a 304');
    }

    public function test_disabled_sources_are_never_fetched(): void
    {
        Http::fake();

        $out = $this->ingestion()->run(['binance_announcements']);

        $this->assertSame(['binance_announcements' => ['status' => 'disabled']], $out);
        Http::assertNothingSent();
    }

    public function test_fear_greed_backfill_is_idempotent_and_lags_first_seen(): void
    {
        Http::fake(['api.alternative.me/*' => Http::response($this->fixture('fng.json'), 200)]);

        $this->assertSame(3, $this->ingestion()->backfillFearGreed(3)['metrics']);
        $this->assertSame(0, $this->ingestion()->backfillFearGreed(3)['metrics']);

        $row = MarketContextMetric::orderByDesc('observed_at')->first();
        $this->assertSame('2026-10-06 00:00:00', $this->rawStored('mctx_metrics', $row->id, 'observed_at'));
        $this->assertSame('2026-10-07 00:00:00', $this->rawStored('mctx_metrics', $row->id, 'first_seen_at'));
    }

    public function test_retention_prunes_in_chunks_and_keeps_recent_rows(): void
    {
        $old = $this->event('BTC', '2026-01-01 00:00:00');
        $keep = $this->event('BTC', '2026-10-05 00:00:00');
        $keep->update(['excerpt' => 'old excerpt']);
        MarketContextEvent::whereKey($keep->id)->update(['excerpt' => 'x', 'first_seen_at' => '2026-08-01 00:00:00', 'published_at' => '2026-08-01 00:00:00']);
        $this->metric('ALL', 'fear_greed', 50, '2024-01-01 00:00:00');
        $this->metric('ALL', 'fear_greed', 51, '2026-10-05 00:00:00');
        config(['market_context.retention.chunk' => 50]);

        $result = app(\App\Domain\MarketContext\MarketContextRetention::class)->prune();

        $this->assertSame(1, $result['events']);
        $this->assertSame(1, $result['excerpts']);
        $this->assertSame(1, $result['metrics']);
        $this->assertNull(MarketContextEvent::find($keep->id)->excerpt);
        $this->assertNull(MarketContextEvent::find($old->id));
        $this->assertSame(0, MarketContextEventInstrument::where('event_id', $old->id)->count(), 'links cascade with the event');
    }
}
