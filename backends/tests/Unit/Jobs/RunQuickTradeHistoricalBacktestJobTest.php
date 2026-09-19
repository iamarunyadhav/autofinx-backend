<?php

namespace Tests\Unit\Jobs;

use App\Jobs\RunQuickTradeHistoricalBacktestJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Tests\TestCase;

/**
 * Hardening pass: retry/backoff config against a rate-limited Binance REST
 * endpoint, and per-symbol/timeframe/horizon dedup via Laravel's own
 * ShouldBeUnique mechanism (Illuminate\Bus\UniqueLock -- the exact
 * primitive the framework's dispatcher itself uses to decide whether a
 * second dispatch for the same scope may proceed).
 */
class RunQuickTradeHistoricalBacktestJobTest extends TestCase
{
    private function job(string $symbol = 'BTCUSDT', ?string $runId = null, int $horizon = 60): RunQuickTradeHistoricalBacktestJob
    {
        return new RunQuickTradeHistoricalBacktestJob('binance', $symbol, '1m', $horizon, 500, $runId);
    }

    public function test_tries_is_capped_and_backoff_is_staged_not_instant(): void
    {
        $job = $this->job();

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120], $job->backoff());
    }

    public function test_unique_id_scopes_on_exchange_symbol_timeframe_and_horizon_only(): void
    {
        // Two dispatch requests for the exact same trading scope but
        // different BacktestRun ids/candle limits (e.g. the periodic
        // command firing twice in quick succession) must collide on the
        // same unique key -- that is the whole point of ShouldBeUnique
        // here: prevent two concurrent runs corrupting/duplicating the
        // same symbol+timeframe+horizon's backfilled candles.
        $a = $this->job('BTCUSDT', 'run-a', 60);
        $b = $this->job('BTCUSDT', 'run-b', 60);
        $differentSymbol = $this->job('ETHUSDT', 'run-c', 60);
        $differentHorizon = $this->job('BTCUSDT', 'run-d', 120);

        $this->assertSame($a->uniqueId(), $b->uniqueId());
        $this->assertNotSame($a->uniqueId(), $differentSymbol->uniqueId());
        $this->assertNotSame($a->uniqueId(), $differentHorizon->uniqueId());
    }

    public function test_a_second_dispatch_for_the_same_scope_cannot_acquire_the_unique_lock_while_the_first_is_running(): void
    {
        $lock = new UniqueLock(app(Cache::class));

        $first = $this->job('BTCUSDT', 'run-first', 60);
        $second = $this->job('BTCUSDT', 'run-second', 60);
        $unrelated = $this->job('ETHUSDT', 'run-unrelated', 60);

        $this->assertTrue($lock->acquire($first), 'first dispatch for a fresh scope must acquire the lock');
        $this->assertFalse($lock->acquire($second), 'a concurrent dispatch for the SAME scope must be blocked while the first is still holding the lock');
        $this->assertTrue($lock->acquire($unrelated), 'a dispatch for a different symbol must never be blocked by another scope\'s lock');

        $lock->release($first);
        $this->assertTrue($lock->acquire($second), 'once the first run releases its lock, a new dispatch for the same scope may proceed');
    }
}
