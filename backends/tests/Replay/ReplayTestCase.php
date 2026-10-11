<?php

namespace Tests\Replay;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\CreatesApplication;
use Tests\Support\RatioBotReplay\ReplayHarness;

/**
 * Base for the replay tests: isolated SQLite FILE database (so a run can be
 * resumed by month), migrated once into that file only. Never the dev MySQL.
 */
abstract class ReplayTestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        ReplayHarness::assertIsolatedDatabase();
    }

    /** Fresh isolated DB file (drops everything first). Only ever touches the guarded sqlite file. */
    protected function freshReplayDatabase(): void
    {
        ReplayHarness::assertIsolatedDatabase();
        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        DB::connection()->getPdo()->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=OFF;');
    }
}
