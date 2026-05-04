<?php

namespace Tests\Unit\FutureTrade;

use App\Domain\FutureTrade\FutureTradeBotService;
use App\Domain\Portfolio\LivePortfolioGuardService;
use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeBotState;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

class FutureTradeDailyCycleTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_daily_loss_limit_locks_bot_until_end_of_day(): void
    {
        Carbon::setTestNow('2026-05-04 10:30:00');

        $service = new FutureTradeBotService(new LivePortfolioGuardService());
        $config = new FutureTradeBotConfig([
            'workspace_id' => '00000000-0000-0000-0000-000000000099',
            'enabled' => true,
            'settings' => [
                'maxDailyLossPercent' => 1.2,
            ],
        ]);
        $state = new FutureTradeBotState([
            'daily_loss' => 1.25,
            'quantity' => 0,
            'trading_day' => '2026-05-04',
            'status' => 'Running',
            'state' => 'SCANNING',
            'connection_status' => 'Connected',
        ]);

        $locked = $this->invoke($service, 'applyDailyLossLock', [$config, $state]);

        $this->assertTrue($locked);
        $this->assertSame('Stopped', $state->status);
        $this->assertSame('DAILY_LOSS_LOCKED', $state->state);
        $this->assertSame('DAILY_LOSS_LIMIT_HIT', $state->current_signal);
        $this->assertNotNull($state->daily_stop_until);
    }

    public function test_new_day_resets_daily_loss_and_clears_lock_for_enabled_bot(): void
    {
        Carbon::setTestNow('2026-05-05 08:00:00');

        $service = new FutureTradeBotService(new LivePortfolioGuardService());
        $config = new FutureTradeBotConfig([
            'workspace_id' => '00000000-0000-0000-0000-000000000099',
            'enabled' => true,
            'settings' => [
                'maxDailyLossPercent' => 1.2,
            ],
        ]);
        $state = new FutureTradeBotState([
            'daily_profit' => 0.2,
            'daily_loss' => 1.4,
            'trade_count_today' => 4,
            'trading_day' => '2026-05-04',
            'daily_stop_until' => '2026-05-04 23:59:59',
            'status' => 'Stopped',
            'state' => 'DAILY_LOSS_LOCKED',
            'connection_status' => 'Daily loss limit reached',
        ]);

        $refreshed = $this->invoke($service, 'refreshDailyCycleState', [$config, $state]);

        $this->assertSame('2026-05-05', $refreshed->trading_day?->toDateString());
        $this->assertSame(0.0, (float) $refreshed->daily_loss);
        $this->assertSame(0.0, (float) $refreshed->daily_profit);
        $this->assertSame(0, (int) $refreshed->trade_count_today);
        $this->assertNull($refreshed->daily_stop_until);
        $this->assertSame('Running', $refreshed->status);
        $this->assertSame('SCANNING', $refreshed->state);
    }

    private function invoke(FutureTradeBotService $service, string $method, array $arguments): mixed
    {
        $reflection = new ReflectionMethod($service, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($service, $arguments);
    }
}
