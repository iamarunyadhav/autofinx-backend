<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Redis;
use Tests\CreatesApplication;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use RefreshDatabase;
    use WithFaker;

    protected function tearDown(): void
    {
        $this->clearMarketFeedRedisKeys();

        // RealtimeMarketFeedService caches Symbol row IDs and write-throttle
        // timestamps in private static (in-process) arrays as a performance
        // optimization for the real WebSocket ingest loop. RefreshDatabase
        // only resets the SQL database between tests, not PHP statics — so
        // without this, a Symbol row an earlier test cached the id of gets
        // deleted by the next test's DB refresh, leaving a dangling id that
        // makes every later MarketFeedStatus lookup for that symbol silently
        // miss, eventually surfacing as an unrelated null-pointer crash in
        // whatever test happens to run afterward.
        if (class_exists(\App\Domain\Market\RealtimeMarketFeedService::class)) {
            // ratioBotWorkspaceCache/robotSessionWorkspaceCache/futureTradeWorkspaceCache
            // (plus their builtAt timestamp) are the same kind of in-process,
            // TTL-refreshed cache as the throttle arrays below — reset for the
            // same reason: without this, a test creating a bot AFTER an
            // earlier test already built the cache (well within the TTL,
            // since a test run takes far less than 30s wall-clock) would
            // silently miss it until the cache happens to go stale.
            foreach ([
                'symbolIdCache',
                'feedStatusWriteThrottle',
                'ratioBotStateWriteThrottle',
                'liveQuoteWriteThrottle',
                'liveFeedSideEffectThrottle',
                'liveOpenCandleWriteThrottle',
                'ratioBotWorkspaceCache',
                'robotSessionWorkspaceCache',
                'futureTradeWorkspaceCache',
            ] as $property) {
                if (property_exists(\App\Domain\Market\RealtimeMarketFeedService::class, $property)) {
                    $ref = new \ReflectionProperty(\App\Domain\Market\RealtimeMarketFeedService::class, $property);
                    $ref->setAccessible(true);
                    $ref->setValue(null, []);
                }
            }
            if (property_exists(\App\Domain\Market\RealtimeMarketFeedService::class, 'workspaceInterestCacheBuiltAtMs')) {
                $ref = new \ReflectionProperty(\App\Domain\Market\RealtimeMarketFeedService::class, 'workspaceInterestCacheBuiltAtMs');
                $ref->setAccessible(true);
                $ref->setValue(null, 0);
            }
        }

        parent::tearDown();
    }

    /**
     * RefreshDatabase resets SQL between tests but never touches Redis, and
     * this suite runs its own market-feed-ingest tests against a real Redis
     * connection (phpunit.xml points REDIS_DB at an isolated index, but that
     * only separates the suite from a locally-running market:ws-consume
     * daemon — it does nothing to stop one test's own ingested tick from
     * bleeding into the next test in the SAME run). Several individual test
     * classes already hand-roll a `clearMarketRedis()` tearDown for the
     * exact symbols they use (see RatioBotStaleFeedSymbolIsolationTest,
     * DetectStaleFeedsJobSafetyNetTest) — this generalizes that pattern to
     * every test, for every symbol, so a class that forgets it (or a test
     * that writes a symbol another class doesn't know to clear) can't leave
     * a stale-vs-fresh assertion flaky depending on run order. Scoped to the
     * known market-feed key prefixes only — never a blind FLUSHDB — so it
     * can't clobber unrelated Redis-backed test state (e.g. rate limiters).
     */
    private function clearMarketFeedRedisKeys(): void
    {
        $patterns = [
            'market:live:*',
            'live-feed:*',
            'price:binance*:*',
            'ts:binance*:*',
            'autofinx:market:*',
            'autofinx:market-feed:*',
        ];

        // Predis's KEYS command prepends the configured connection prefix to
        // the glob pattern server-side but does NOT strip it back off the
        // returned matches (unlike GET/SET/DEL, which auto-(de)prefix
        // transparently) — feeding a returned key straight back into del()
        // double-prefixes it into a key that never existed, silently
        // no-op'ing the "cleanup" every single time. Strip the same prefix
        // Laravel configured before deleting.
        $prefix = (string) config('database.redis.options.prefix', '');

        foreach ($patterns as $pattern) {
            try {
                $keys = Redis::keys($pattern);
            } catch (\Throwable) {
                continue;
            }

            foreach ((array) $keys as $key) {
                $key = (string) $key;
                if ($prefix !== '' && str_starts_with($key, $prefix)) {
                    $key = substr($key, strlen($prefix));
                }

                try {
                    Redis::del($key);
                } catch (\Throwable) {
                }
            }
        }
    }
}
