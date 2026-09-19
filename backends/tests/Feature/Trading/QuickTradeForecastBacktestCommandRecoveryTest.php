<?php

namespace Tests\Feature\Trading;

use App\Models\BacktestRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves `quick-trade:forecast-backtest`'s stuck-run recovery sweep (added
 * alongside the RunQuickTradeHistoricalBacktestJob hardening pass) marks a
 * BacktestRun that has been stuck in status=running past the timeout as
 * failed with a greppable error_message, logs it, and leaves genuinely
 * recent/in-progress runs alone.
 */
class QuickTradeForecastBacktestCommandRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(string $status, ?\Illuminate\Support\Carbon $startedAt): BacktestRun
    {
        return BacktestRun::create([
            'id' => (string) Str::uuid(),
            'bot_type' => 'quick_trade',
            'exchange' => 'binance',
            'symbol' => 'BTCUSDT',
            'timeframe' => '1m',
            'horizon_seconds' => 60,
            'candle_limit' => 500,
            'status' => $status,
            'queued_at' => now()->subHour(),
            'started_at' => $startedAt,
        ]);
    }

    public function test_a_run_stuck_running_past_the_timeout_is_marked_failed(): void
    {
        Log::spy();

        $stuck = $this->makeRun('running', now()->subMinutes(45));

        $this->artisan('quick-trade:forecast-backtest')->assertExitCode(0);

        $stuck->refresh();
        $this->assertSame('failed', $stuck->status);
        $this->assertSame('stuck_job_recovered_by_timeout', $stuck->error_message);
        $this->assertNotNull($stuck->finished_at);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message) => str_contains($message, 'recovered stuck BacktestRun'))
            ->once();
    }

    public function test_a_recently_started_running_run_is_left_untouched(): void
    {
        $recent = $this->makeRun('running', now()->subMinutes(5));

        $this->artisan('quick-trade:forecast-backtest')->assertExitCode(0);

        $recent->refresh();
        $this->assertSame('running', $recent->status);
        $this->assertNull($recent->error_message);
    }

    public function test_a_completed_run_is_never_touched_regardless_of_age(): void
    {
        $completed = $this->makeRun('completed', now()->subDays(2));

        $this->artisan('quick-trade:forecast-backtest')->assertExitCode(0);

        $completed->refresh();
        $this->assertSame('completed', $completed->status);
    }
}
