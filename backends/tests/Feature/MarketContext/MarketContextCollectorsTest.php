<?php

namespace Tests\Feature\MarketContext;

use App\Domain\MarketContext\Collectors\BinanceAnnouncementsCollector;
use App\Domain\MarketContext\Collectors\BinanceDerivativesMetricsCollector;
use App\Domain\MarketContext\Collectors\FearGreedCollector;
use App\Domain\MarketContext\Collectors\RssNewsCollector;
use App\Domain\MarketContext\Collectors\SourceFetchException;
use App\Domain\MarketContext\Fetch\SafeHttpFetcher;
use App\Domain\MarketContext\MarketContextIngestionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Recorded real responses (tests/Fixtures/MarketContext, captured 2026-10-06) with Http::fake: no live network. */
class MarketContextCollectorsTest extends MarketContextTestCase
{
    private function collector(string $id): object
    {
        return app(MarketContextIngestionService::class)->makeCollector($id, config('market_context.sources.'.$id));
    }

    public function test_fear_greed_parses_recorded_response_and_sends_conditional_headers(): void
    {
        Http::fake(['api.alternative.me/*' => Http::response($this->fixture('fng.json'), 200, ['ETag' => '"abc"'])]);

        $result = $this->collector('fear_greed')->collect('"old"', 'Mon, 05 Oct 2026 00:00:00 GMT', $this->now);

        $this->assertCount(3, $result->metrics);
        $this->assertSame(73.0, $result->metrics[0]->value);
        $this->assertSame('fear_greed', $result->metrics[0]->metric);
        $this->assertSame('ALL', $result->metrics[0]->instrument);
        $this->assertSame('2026-10-06 00:00:00', $result->metrics[0]->observedAt->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($result->metrics[0]->firstSeenAt, 'live rows are first seen "now"');
        $this->assertSame('"abc"', $result->etag);
        Http::assertSent(fn ($r) => $r->hasHeader('If-None-Match', '"old"') && $r->hasHeader('If-Modified-Since', 'Mon, 05 Oct 2026 00:00:00 GMT')
            && str_starts_with($r->url(), 'https://api.alternative.me/fng/?limit=3'));
    }

    public function test_fear_greed_304_is_not_modified_and_bad_payload_is_rejected(): void
    {
        Http::fakeSequence('api.alternative.me/*')->push('', 304)->push('{"data":"nope"}', 200)->push('{"data":[{"value":"250","timestamp":"1791244800"}],"metadata":{"error":null}}', 200);

        $this->assertTrue($this->collector('fear_greed')->collect('"x"', null, $this->now)->notModified);
        foreach ([1, 2] as $_) {
            try {
                $this->collector('fear_greed')->collect(null, null, $this->now);
                $this->fail('expected SourceFetchException');
            } catch (SourceFetchException $e) {
                $this->assertContains($e->errorCode, ['BAD_FORMAT', 'EMPTY']);
            }
        }
    }

    public function test_fear_greed_backfill_lags_first_seen_by_a_day_and_caps_days(): void
    {
        Http::fake(['api.alternative.me/*' => Http::response($this->fixture('fng.json'), 200)]);

        $result = $this->collector('fear_greed')->backfill(999999, $this->now);

        $this->assertSame('2026-10-07 00:00:00', $result->metrics[0]->firstSeenAt->utc()->format('Y-m-d H:i:s'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'limit=1500'));
    }

    public function test_rss_collector_parses_fed_ecb_coindesk_cointelegraph_fixtures(): void
    {
        $cases = ['rss_fed' => ['rss_fed.xml', 3, true], 'rss_ecb' => ['rss_ecb.xml', 15, true],
            'rss_coindesk' => ['rss_coindesk.xml', 2, false], 'rss_cointelegraph' => ['rss_cointelegraph.xml', 2, false]];
        foreach ($cases as $id => [$file, $count, $excerpt]) {
            $items = $this->collector($id)->parse($this->fixture($file), $this->now);
            $this->assertCount($count, $items, $id);
            $this->assertNotSame('', $items[0]->title);
            $this->assertSame($id, $items[0]->source);
            $this->assertNotNull($items[0]->externalId);
            $this->assertStringStartsWith('https://', (string) $items[0]->url);
            if (! $excerpt) {
                $this->assertNull($items[0]->excerpt, 'headline-only sources store no body text');
            }
        }
        $fed = $this->collector('rss_fed')->parse($this->fixture('rss_fed.xml'), $this->now);
        $this->assertSame('2026-10-05 20:30:00', $fed[0]->publishedAt->utc()->format('Y-m-d H:i:s'));
        $ecb = $this->collector('rss_ecb')->parse($this->fixture('rss_ecb.xml'), $this->now);
        $this->assertSame(trim($ecb[0]->title), $ecb[0]->title, 'ECB titles carry leading spaces in the feed');
        $this->assertSame('2026-10-05 08:00:00', $ecb[0]->publishedAt->utc()->format('Y-m-d H:i:s'), '+0200 offset converted to UTC');
    }

    public function test_rss_collector_strips_markup_clamps_future_dates_and_rejects_doctype_and_oversize(): void
    {
        $xml = '<?xml version="1.0"?><rss version="2.0"><channel><item><title><![CDATA[<b>Hacked</b> &amp; drained]]></title>'
            .'<link>http://insecure.test/a</link><guid>g1</guid><pubDate>Fri, 01 Jan 2100 00:00:00 +0000</pubDate></item></channel></rss>';
        $items = $this->collector('rss_coindesk')->parse($xml, $this->now);

        $this->assertSame('Hacked & drained', $items[0]->title);
        $this->assertNull($items[0]->url, 'non-https links are dropped');
        $this->assertSame($this->now->timestamp, $items[0]->publishedAt->timestamp, 'future pubDate clamped to now');

        $this->expectException(SourceFetchException::class);
        $this->collector('rss_coindesk')->parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><rss/>', $this->now);
    }

    public function test_rss_collector_rejects_oversize_documents(): void
    {
        $this->expectException(SourceFetchException::class);
        $this->collector('rss_coindesk')->parse(str_repeat('a', 262145), $this->now);
    }

    public function test_binance_announcements_parser_reads_recorded_catalogs(): void
    {
        $collector = $this->collector('binance_announcements');
        $listing = $collector->parse($this->fixture('binance_announcements_48.json'), 'listing', $this->now);
        $delisting = $collector->parse($this->fixture('binance_announcements_161.json'), 'delisting', $this->now);

        $this->assertCount(5, $listing);
        $this->assertSame('listing', $listing[0]->hint);
        $this->assertStringStartsWith('binance-', $listing[0]->externalId);
        $this->assertStringStartsWith('https://www.binance.com/en/support/announcement/detail/', $listing[0]->url);
        $this->assertSame('2026-10-01 06:30:01', $listing[0]->publishedAt->utc()->format('Y-m-d H:i:s'));
        $this->assertNotEmpty($delisting);
        $this->expectException(SourceFetchException::class);
        $collector->parse('{"success":false}', 'listing', $this->now);
    }

    public function test_binance_derivatives_stores_only_completed_buckets_through_the_circuit_breaker(): void
    {
        $this->onlyBtc();
        Http::fake([
            'fapi.binance.com/futures/data/openInterestHist*' => Http::response($this->fixture('fapi_open_interest_hist.json')),
            'fapi.binance.com/futures/data/globalLongShortAccountRatio*' => Http::response($this->fixture('fapi_long_short.json')),
            'fapi.binance.com/futures/data/takerlongshortRatio*' => Http::response($this->fixture('fapi_taker.json')),
        ]);

        $result = $this->collector('binance_derivatives')->collect(null, null, $this->now);

        $byMetric = collect($result->metrics)->groupBy('metric');
        $this->assertCount(3, $byMetric['open_interest_usd']);
        $this->assertSame(8083009900.536926, $byMetric['open_interest_usd'][0]->value);
        $this->assertSame(1.0803, $byMetric['long_short_ratio'][0]->value);
        $this->assertSame(0.9522, $byMetric['taker_buy_sell_ratio'][0]->value);
        $this->assertSame('BTC', $result->metrics[0]->instrument);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'symbol=BTCUSDT&period=1h&limit=6'));

        // At 02:30 the 02:00 bucket (closes 03:00) is still open and must be dropped.
        $early = $this->now->copy()->setTime(2, 30);
        $collector = $this->collector('binance_derivatives');
        $parsed = $collector->parse($this->fixture('fapi_open_interest_hist.json'), ['open_interest_usd' => 'sumOpenInterestValue'], 'BTC', 'CRYPTO', 3600, $early);
        $this->assertCount(2, $parsed, 'the 02:00 bucket is still open at 02:30');
    }

    public function test_binance_derivatives_aborts_when_the_shared_breaker_is_open_and_a_429_trips_it(): void
    {
        $this->onlyBtc();
        Http::fake(['fapi.binance.com/*' => Http::response('{"code":-1003,"msg":"Too many requests"}', 429)]);

        try {
            $this->collector('binance_derivatives')->collect(null, null, $this->now);
            $this->fail('expected failure');
        } catch (SourceFetchException $e) {
            $this->assertSame('BINANCE_CIRCUIT_OPEN', $e->errorCode);
        }
        // The 429 opened the shared breaker on the first call; the remaining calls were never sent.
        Http::assertSentCount(1);
    }

    public function test_fetcher_refuses_unregistered_plain_http_and_oversize(): void
    {
        $fetcher = app(SafeHttpFetcher::class);
        foreach (['http://api.alternative.me/fng/', 'https://evil.test/fng/', 'https://user:pw@api.alternative.me/fng/', 'https://api.alternative.me:8443/fng/'] as $url) {
            try {
                $fetcher->get($url);
                $this->fail("should refuse $url");
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        Http::assertNothingSent();

        Http::fake(['api.alternative.me/*' => Http::response(str_repeat('x', 262145), 200)]);
        $this->expectException(\RuntimeException::class);
        $fetcher->get('https://api.alternative.me/fng/?limit=1');
    }

    public function test_registry_fed_and_ecb_urls_match_the_nexus_registry_and_every_url_is_https(): void
    {
        $this->assertSame(config('nexus.sources.federal-reserve.url'), config('market_context.sources.rss_fed.url'));
        $this->assertSame(config('nexus.sources.ecb.url'), config('market_context.sources.rss_ecb.url'));
        foreach (config('market_context.sources') as $id => $source) {
            $this->assertStringStartsWith('https://', $source['url'], $id);
            $this->assertFalse(isset(parse_url($source['url'])['user']), $id);
        }
        $this->assertFalse(config('market_context.sources.binance_announcements.enabled'), 'unofficial endpoint stays off by default');
        Cache::flush();
    }

    private function onlyBtc(): void
    {
        config(['market_context.profiles' => ['BTC' => config('market_context.profiles.BTC')]]);
    }
}
