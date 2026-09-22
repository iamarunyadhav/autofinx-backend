<?php

namespace Tests\Feature\Market;

use App\Console\Commands\MarketWsConsumerCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 2026-09-22 transport hotfix: market:ws-consume's own per-lane singleton
 * lock is what actually guarantees "no duplicate authoritative worker for
 * the same lane" -- these tests exercise the real lock-rejection code path
 * (which returns immediately, before ever attempting a websocket
 * connection) rather than mocking it out.
 */
class FuturesLaneSingletonLockTest extends TestCase
{
    public function test_duplicate_public_lane_worker_is_rejected(): void
    {
        $this->assertDuplicateLaneWorkerRejected('public');
    }

    public function test_duplicate_market_lane_worker_is_rejected(): void
    {
        $this->assertDuplicateLaneWorkerRejected('market');
    }

    private function assertDuplicateLaneWorkerRejected(string $lane): void
    {
        $lockKey = MarketWsConsumerCommand::lockKeyFor('binance', 'futures', $lane);
        $heartbeatPath = MarketWsConsumerCommand::heartbeatPathFor('futures', $lane);

        // Simulate a genuinely live worker already owning this lane: hold
        // the lock AND leave a fresh heartbeat behind it (heartbeatFresh()
        // gates whether the command even attempts a stale-lock takeover).
        $existingLock = Cache::lock($lockKey, 120);
        $this->assertTrue($existingLock->get(), 'Precondition: test must be able to acquire the lane lock itself.');

        @mkdir(dirname($heartbeatPath), 0777, true);
        file_put_contents($heartbeatPath, json_encode([
            'last_message_epoch' => time(),
            'last_message_at' => now()->toIso8601String(),
        ]));

        try {
            $exitCode = Artisan::call('market:ws-consume', [
                '--market-type' => 'futures',
                '--lane' => $lane,
                '--streams' => $lane === 'public' ? 'depth5@500ms' : 'kline_1m',
                '--symbols' => 'BTCUSDT',
                '--max-runtime' => 1,
            ]);

            $this->assertSame(0, $exitCode, 'A duplicate lane worker must exit SUCCESS (reject-and-return), never hang or fail.');
            $this->assertStringContainsString('market_feed_singleton_already_owned', Artisan::output());
        } finally {
            $existingLock->release();
            @unlink($heartbeatPath);
        }
    }

    public function test_public_and_market_lane_locks_are_independent_and_can_be_held_concurrently(): void
    {
        $publicLock = Cache::lock(MarketWsConsumerCommand::lockKeyFor('binance', 'futures', 'public'), 60);
        $marketLock = Cache::lock(MarketWsConsumerCommand::lockKeyFor('binance', 'futures', 'market'), 60);

        try {
            $this->assertTrue($publicLock->get(), 'Public lane lock must be acquirable.');
            $this->assertTrue($marketLock->get(), 'Market lane lock must be acquirable concurrently with public — they are independent namespaces.');
        } finally {
            $publicLock->release();
            $marketLock->release();
        }
    }

    public function test_lane_option_rejected_for_spot_market_type(): void
    {
        $exitCode = Artisan::call('market:ws-consume', [
            '--market-type' => 'spot',
            '--lane' => 'public',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('only meaningful for --market-type=futures', Artisan::output());
    }

    public function test_invalid_lane_value_is_rejected(): void
    {
        $exitCode = Artisan::call('market:ws-consume', [
            '--market-type' => 'futures',
            '--lane' => 'bogus',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--lane must be one of', Artisan::output());
    }

    public function test_lane_run_with_only_unroutable_streams_fails_closed(): void
    {
        $exitCode = Artisan::call('market:ws-consume', [
            '--market-type' => 'futures',
            '--lane' => 'public',
            '--streams' => 'kline_1m',
            '--symbols' => 'BTCUSDT',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No streams classified for lane=public', Artisan::output());
    }
}
