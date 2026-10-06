<?php

namespace Tests\Feature\MarketContext;

use App\Models\MarketContextSnapshot;
use App\Models\MarketContextSourceHealth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class MarketContextCommandsTest extends MarketContextTestCase
{
    private function healthy(string $source, array $over = []): void
    {
        MarketContextSourceHealth::create($over + ['source' => $source, 'status' => 'OK', 'last_success_at' => $this->now->copy()->subMinutes(5),
            'consecutive_failures' => 0]);
    }

    public function test_verify_passes_on_a_healthy_fixture_and_exits_zero(): void
    {
        config(['market_context.profiles' => ['BTC' => array_merge(config('market_context.profiles.BTC'), ['collectors' => ['fear_greed']])]]);
        config(['market_context.sources' => array_intersect_key(config('market_context.sources'), ['fear_greed' => 1, 'binance_announcements' => 1])]);
        $this->healthy('fear_greed');
        $this->metric('ALL', 'fear_greed', 50, '2026-10-06 00:00:00', '2026-10-06 03:00:00');
        $this->artisan('market-context:snapshot', ['instrument' => 'BTC'])->assertSuccessful();

        $this->artisan('market-context:verify')
            ->expectsOutputToContain('PASS  Module flag')
            ->expectsOutputToContain('PASS  Tables present')
            ->expectsOutputToContain('PASS  Instants are read as UTC')
            ->expectsOutputToContain('PASS  No TIMESTAMP columns')
            ->expectsOutputToContain('PASS  fear_greed: last success 5 min ago')
            ->expectsOutputToContain('PASS  binance_announcements: disabled in the registry')
            ->expectsOutputToContain('PASS  No event published after it was first seen')
            ->expectsOutputToContain('PASS  BTC live snapshot')
            ->expectsOutputToContain('0 FAIL')
            ->assertExitCode(0);
    }

    public function test_verify_warns_when_off_empty_or_stale_but_still_exits_zero(): void
    {
        config(['market_context.enabled' => false]);
        $this->healthy('fear_greed', ['last_success_at' => $this->now->copy()->subDays(2)]);

        $this->artisan('market-context:verify')
            ->expectsOutputToContain('WARN  Module flag')
            ->expectsOutputToContain('WARN  fear_greed: last success 2880 min ago')
            ->expectsOutputToContain('WARN  rss_fed: never collected')
            ->expectsOutputToContain('WARN  BTC live snapshot: freshness=unavailable risk=UNAVAILABLE')
            ->assertExitCode(0);
    }

    public function test_verify_fails_with_exit_1_on_open_breaker_point_in_time_leak_and_bad_config(): void
    {
        $this->healthy('rss_fed', ['status' => 'OPEN', 'consecutive_failures' => 5, 'last_error_code' => 'HTTP_500', 'disabled_until' => $this->now->copy()->addMinutes(30)]);
        $this->event('BTC', '2026-10-06 03:00:00', '2026-10-06 03:20:00');          // published after first seen: a future leak
        config(['market_context.sources.rss_ecb.url' => 'http://insecure.test/feed']);

        $this->artisan('market-context:verify')
            ->expectsOutputToContain('FAIL  rss_fed: circuit breaker open')
            ->expectsOutputToContain('FAIL  No event published after it was first seen')
            ->expectsOutputToContain('FAIL  Registry URLs are https: rss_ecb')
            ->assertExitCode(1);
    }

    public function test_verify_fails_with_exit_1_when_tables_are_missing(): void
    {
        Schema::drop('mctx_event_instruments');
        Schema::drop('mctx_events');

        $this->artisan('market-context:verify')
            ->expectsOutputToContain('FAIL  Tables present: missing: mctx_events, mctx_event_instruments')
            ->assertExitCode(1);
    }

    public function test_snapshot_command_stores_one_row_per_enabled_profile_and_dry_stores_none(): void
    {
        $this->artisan('market-context:snapshot', ['--dry' => true])->assertSuccessful();
        $this->assertSame(0, MarketContextSnapshot::count());

        $this->artisan('market-context:snapshot')->expectsOutputToContain('as_of=2026-10-06T03:30:00+00:00')->assertSuccessful();
        $this->artisan('market-context:snapshot')->assertSuccessful();   // same bucket: idempotent
        $this->assertSame(6, MarketContextSnapshot::count());

        $this->artisan('market-context:snapshot', ['instrument' => 'XAU'])->assertFailed();   // disabled placeholder
    }

    public function test_collect_command_runs_selected_sources_and_never_touches_disabled_ones(): void
    {
        Http::fake(['api.alternative.me/*' => Http::response($this->fixture('fng.json'), 200)]);

        $this->artisan('market-context:collect', ['--source' => ['fear_greed', 'binance_announcements']])
            ->expectsOutputToContain('fear_greed')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $this->artisan('market-context:collect', ['--backfill-fng' => 3])->expectsOutputToContain('fear_greed backfill')->assertSuccessful();
    }

    public function test_prune_command_reports_counts(): void
    {
        $this->artisan('market-context:prune')->expectsOutputToContain('"events":0')->assertSuccessful();
    }
}
