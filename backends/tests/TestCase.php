<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\CreatesApplication;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    use RefreshDatabase;
    use WithFaker;

    protected function tearDown(): void
    {
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
            foreach (['symbolIdCache', 'feedStatusWriteThrottle', 'ratioBotStateWriteThrottle'] as $property) {
                if (property_exists(\App\Domain\Market\RealtimeMarketFeedService::class, $property)) {
                    $ref = new \ReflectionProperty(\App\Domain\Market\RealtimeMarketFeedService::class, $property);
                    $ref->setAccessible(true);
                    $ref->setValue(null, []);
                }
            }
        }

        parent::tearDown();
    }
}
