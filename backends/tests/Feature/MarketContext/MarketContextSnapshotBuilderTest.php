<?php

namespace Tests\Feature\MarketContext;

use App\Domain\MarketContext\Data\ContextQuery;
use App\Domain\MarketContext\SnapshotBuilder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class MarketContextSnapshotBuilderTest extends MarketContextTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Two sources keep "fresh" checkable without seeding every registry source.
        config(['market_context.profiles.BTC.collectors' => ['fear_greed', 'rss_coindesk']]);
    }

    private function build(string $asOf, string $instrument = 'BTC', ?int $lookback = null): array
    {
        return app(SnapshotBuilder::class)->build(new ContextQuery('CRYPTO', $instrument, Carbon::parse($asOf, 'UTC'), $lookback ?? 86400))->toArray();
    }

    public function test_a_row_first_seen_after_as_of_never_appears_in_that_snapshot(): void
    {
        $this->event('BTC', '2026-10-06 02:00:00', title: 'Seen before');
        $late = $this->event('BTC', '2026-10-06 03:00:01', '2026-10-06 02:30:00', title: 'Seen one second after as_of'); // published earlier, learned later
        $this->metric('ALL', 'fear_greed', 20, '2026-10-06 00:00:00', '2026-10-06 03:00:01');   // first seen after as_of
        $this->metric('ALL', 'fear_greed', 60, '2026-10-05 00:00:00', '2026-10-05 00:00:00');

        $at = $this->build('2026-10-06 03:00:00');

        $titles = array_column($at['drivers'], 'title');
        $this->assertContains('Seen before', $titles);
        $this->assertNotContains('Seen one second after as_of', $titles);
        $this->assertNotContains($late->id, array_column($at['drivers'], 'event_id'));
        $this->assertSame(60.0, $at['metrics']['fear_greed'], 'the later-seen 20 must not leak into the past');

        $after = $this->build('2026-10-06 03:00:01');
        $this->assertContains('Seen one second after as_of', array_column($after['drivers'], 'title'));
        $this->assertSame(20.0, $after['metrics']['fear_greed']);
    }

    public function test_snapshot_is_a_pure_function_of_rows_and_as_of(): void
    {
        $this->event('BTC', '2026-10-06 01:00:00');
        $this->metric('ALL', 'fear_greed', 40, '2026-10-06 00:00:00');
        $a = $this->build('2026-10-06 03:00:00');

        $this->travelTo(Carbon::parse('2027-03-01 12:34:56', 'UTC'));   // wall clock must not matter
        $b = $this->build('2026-10-06 03:00:00');

        $this->assertSame($a, $b);
        $this->assertSame('2026-10-06T03:00:00+00:00', $a['provenance']['snapshot_computed_at']);
    }

    public function test_no_data_is_reported_as_unavailable_not_neutral(): void
    {
        $s = $this->build('2026-10-06 03:00:00');

        $this->assertSame('unavailable', $s['freshness']['state']);
        $this->assertNull($s['freshness']['newest_item_age_s']);
        $this->assertSame('UNAVAILABLE', $s['risk']['level']);
        $this->assertFalse($s['risk']['veto_hint']);
        $this->assertNull($s['sentiment']['score']);
        $this->assertSame('none', $s['sentiment']['method']);
        $this->assertSame([], $s['metrics']);
        $this->assertSame([], $s['drivers']);
        $this->assertSame(['unavailable', 'unavailable'], array_column($s['freshness']['sources'], 'status'));
    }

    public function test_risk_decays_with_event_age_and_follows_severity_priors(): void
    {
        $fresh = $this->event('BTC', '2026-10-06 03:00:00', severity: 'CRITICAL', category: 'SECURITY_INCIDENT');
        $s = $this->build('2026-10-06 03:00:00');
        $this->assertSame('CRITICAL', $s['risk']['level']);
        $this->assertTrue($s['risk']['veto_hint']);
        $this->assertSame(0.9, $s['drivers'][0]['impact']);          // 0.9 prior x 1.0 category x 1.0 relevance x decay 1
        $this->assertContains('SECURITY_INCIDENT', $s['risk']['flags']);

        // One half-life (24h for SECURITY_INCIDENT) later the impact halves; lookback widened to keep the row.
        $aged = $this->build('2026-10-07 03:00:00', lookback: 172800);
        $this->assertSame(0.45, $aged['drivers'][0]['impact']);

        // A LOW headline from a market-wide-irrelevant category stays LOW with no veto hint.
        $fresh->delete();
        $this->event('BTC', '2026-10-06 03:00:00', severity: 'LOW', category: 'CRYPTO_MARKET_NEWS');
        $low = $this->build('2026-10-06 03:00:00');
        $this->assertSame('LOW', $low['risk']['level']);
        $this->assertFalse($low['risk']['veto_hint']);
    }

    public function test_events_outside_the_lookback_or_for_other_instruments_are_ignored(): void
    {
        $this->event('BTC', '2026-10-04 00:00:00', severity: 'CRITICAL');           // 51h old
        $this->event('ETH', '2026-10-06 02:59:00', severity: 'CRITICAL');           // other instrument

        $this->assertSame([], $this->build('2026-10-06 03:00:00')['drivers']);
        $this->assertCount(1, $this->build('2026-10-06 03:00:00', 'ETH')['drivers']);
    }

    public function test_drivers_are_capped_and_ordered_by_impact(): void
    {
        foreach (range(1, 8) as $i) {
            $this->event('BTC', '2026-10-06 02:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).':00', severity: 'MEDIUM', category: 'REGULATORY_NEWS');
        }
        $this->event('BTC', '2026-10-06 02:59:00', severity: 'HIGH', category: 'EXCHANGE_INCIDENT');

        $drivers = $this->build('2026-10-06 03:00:00')['drivers'];

        $this->assertCount(5, $drivers);
        $this->assertSame('EXCHANGE_INCIDENT', $drivers[0]['category']);
        $impacts = array_column($drivers, 'impact');
        $sorted = $impacts;
        rsort($sorted);
        $this->assertSame($sorted, $impacts);
    }

    public function test_metrics_block_derives_open_interest_change_and_sentiment_from_stored_series(): void
    {
        $this->metric('ALL', 'fear_greed', 85, '2026-10-06 00:00:00');
        $this->metric('BTC', 'open_interest_usd', 1000, '2026-10-05 01:00:00', source: 'binance_derivatives');
        $this->metric('BTC', 'open_interest_usd', 1200, '2026-10-06 01:00:00', source: 'binance_derivatives');
        $this->metric('BTC', 'long_short_ratio', 1.4, '2026-10-06 02:00:00', source: 'binance_derivatives');
        $this->metric('ETH', 'long_short_ratio', 9.9, '2026-10-06 02:00:00', source: 'binance_derivatives');

        $s = $this->build('2026-10-06 03:00:00');

        $this->assertSame(['fear_greed' => 85.0, 'open_interest_usd' => 1200.0, 'open_interest_change_pct' => 20.0, 'long_short_ratio' => 1.4], $s['metrics']);
        $this->assertSame(0.7, $s['sentiment']['score']);
        $this->assertSame('fear_greed_index', $s['sentiment']['method']);
        $this->assertEqualsCanonicalizing(['EXTREME_GREED', 'OPEN_INTEREST_SHIFT', 'STALE_CONTEXT'], $s['risk']['flags']);
    }

    public function test_freshness_reports_per_source_age_and_state(): void
    {
        $this->metric('ALL', 'fear_greed', 50, '2026-10-06 00:00:00');
        $this->event('BTC', '2026-10-06 02:00:00', source: 'rss_coindesk');
        $fresh = $this->build('2026-10-06 03:00:00')['freshness'];

        $this->assertSame('fresh', $fresh['state']);
        $this->assertSame(3600, $fresh['newest_item_age_s']);
        $this->assertSame([['id' => 'fear_greed', 'status' => 'fresh', 'age_s' => 10800], ['id' => 'rss_coindesk', 'status' => 'fresh', 'age_s' => 3600]], $fresh['sources']);

        $stale = $this->build('2026-10-07 20:00:00')['freshness'];   // coindesk stale after 12h, fear_greed after 36h
        $this->assertSame('stale', $stale['state']);
        $this->assertSame('stale', $stale['sources'][1]['status']);

        $this->assertCount(2, $stale['sources'], 'disabled registry sources are not counted');
    }

    public function test_unknown_instrument_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->build('2026-10-06 03:00:00', 'DOGE');
    }

    public function test_disabled_placeholder_profiles_prove_the_asset_class_neutral_shape(): void
    {
        foreach (['XAU' => 'METALS', 'EURUSD' => 'FOREX'] as $instrument => $class) {
            $profile = config('market_context.profiles.'.$instrument);
            $this->assertFalse($profile['enabled']);
            $this->assertSame($class, $profile['asset_class']);
            $this->assertNotEmpty($profile['aliases']);
            $this->assertNotEmpty($profile['macro_drivers']);
        }
        $this->assertSame(['EUR', 'USD'], config('market_context.profiles.EURUSD.components'));
        $this->assertSame(['BTC', 'ETH', 'BNB', 'SOL', 'XRP', 'SUI'], array_keys(array_filter(config('market_context.profiles'), fn ($p) => $p['enabled'])));
    }
}
