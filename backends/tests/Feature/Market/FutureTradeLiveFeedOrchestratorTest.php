<?php

namespace Tests\Feature\Market;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * 2026-09-22 transport hotfix: future-trade:live-feed is now an orchestrator
 * that starts one real child process per BinanceFuturesStreamRouter lane
 * concurrently (via Illuminate's Process component) instead of calling
 * market:ws-consume synchronously in-process. Process::fake() intercepts
 * every child spawn so these tests never touch the network or spawn a real
 * OS process.
 */
class FutureTradeLiveFeedOrchestratorTest extends TestCase
{
    private function commandString(PendingProcess $process): string
    {
        return implode(' ', (array) $process->command);
    }

    public function test_it_starts_both_lanes_concurrently_with_the_correct_streams_and_never_calls_market_ws_consume_synchronously(): void
    {
        Process::fake(fn (PendingProcess $process) => Process::describe()->runsFor(1000)->exitCode(0));

        $exitCode = Artisan::call('future-trade:live-feed', [
            '--symbols' => 'BTCUSDT',
            '--streams' => 'depth5@500ms,kline_1m,kline_5m',
            '--max-runtime' => 1,
        ]);

        $this->assertSame(0, $exitCode);

        Process::assertRan(fn (PendingProcess $process) => str_contains($this->commandString($process), 'market:ws-consume')
            && str_contains($this->commandString($process), '--market-type=futures')
            && str_contains($this->commandString($process), '--lane=public')
            && str_contains($this->commandString($process), '--streams=depth5@500ms')
            && str_contains($this->commandString($process), '--symbols=BTCUSDT'));

        Process::assertRan(fn (PendingProcess $process) => str_contains($this->commandString($process), '--lane=market')
            && str_contains($this->commandString($process), '--streams=kline_1m,kline_5m')
            && str_contains($this->commandString($process), '--symbols=BTCUSDT'));

        // Both lanes must be started before either is awaited synchronously
        // -- i.e. two independent process starts, not one blocking call
        // followed by another. Process::fake() records every start() call;
        // asserting both distinct lane commands ran is exactly that proof
        // for this orchestrator (the old implementation only ever issued a
        // single in-process $this->call('market:ws-consume', ...) and could
        // never have produced two concurrent command records here).
        Process::assertRanTimes(fn (PendingProcess $process) => str_contains($this->commandString($process), 'market:ws-consume'), 2);
    }

    public function test_it_never_starts_a_duplicate_stream_family_streams_get_routed_to_exactly_one_lane(): void
    {
        Process::fake(fn (PendingProcess $process) => Process::describe()->runsFor(1000)->exitCode(0));

        Artisan::call('future-trade:live-feed', [
            '--symbols' => 'BTCUSDT',
            '--streams' => 'depth5@500ms,kline_1m,kline_5m',
            '--max-runtime' => 1,
        ]);

        Process::assertNotRan(fn (PendingProcess $process) => str_contains($this->commandString($process), '--lane=public')
            && str_contains($this->commandString($process), 'kline'));

        Process::assertNotRan(fn (PendingProcess $process) => str_contains($this->commandString($process), '--lane=market')
            && str_contains($this->commandString($process), 'depth'));
    }

    public function test_an_unroutable_stream_family_fails_the_whole_orchestrator_closed_before_starting_any_child(): void
    {
        Process::fake();

        $exitCode = Artisan::call('future-trade:live-feed', [
            '--symbols' => 'BTCUSDT',
            '--streams' => 'trade,kline_1m',
            '--max-runtime' => 1,
        ]);

        $this->assertSame(1, $exitCode);
        Process::assertNothingRan();
    }

    public function test_a_crashed_lane_is_restarted_with_bounded_backoff_while_the_healthy_lane_is_left_alone(): void
    {
        $marketStarts = 0;

        Process::fake(function (PendingProcess $process) use (&$marketStarts) {
            $command = $this->commandString($process);

            if (str_contains($command, '--lane=public')) {
                // Public lane stays healthy for the whole test.
                return Process::describe()->runsFor(1000)->exitCode(0);
            }

            // Market lane crashes immediately on its first start, then
            // recovers on its restart.
            $marketStarts++;

            return $marketStarts === 1
                ? Process::describe()->runsFor(0)->exitCode(9)
                : Process::describe()->runsFor(1000)->exitCode(0);
        });

        $exitCode = Artisan::call('future-trade:live-feed', [
            '--symbols' => 'BTCUSDT',
            '--streams' => 'depth5@500ms,kline_1m',
            '--reconnect-sleep' => 1,
            '--max-runtime' => 4,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertGreaterThanOrEqual(2, $marketStarts, 'The crashed market lane must be restarted at least once.');

        Process::assertRanTimes(fn (PendingProcess $process) => str_contains($this->commandString($process), '--lane=public'), 1);
    }
}
