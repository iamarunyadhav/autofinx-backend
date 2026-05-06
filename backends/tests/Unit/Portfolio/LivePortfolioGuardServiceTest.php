<?php

namespace Tests\Unit\Portfolio;

use App\Domain\Fast2X\Fast2XService;
use App\Domain\FutureTrade\FutureTradeBotService;
use App\Models\Fast2XConfig;
use App\Models\FutureTradeBotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LivePortfolioGuardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fast2x_live_is_downgraded_when_future_trade_live_is_active(): void
    {
        config(['fast2x.enable_live_trading' => true]);

        $workspaceId = '00000000-0000-0000-0000-000000000010';

        $future = FutureTradeBotConfig::create([
            'workspace_id' => $workspaceId,
            'paper_trading' => false,
            'enable_live_trading' => true,
            'enabled' => true,
            'settings' => ['liveTrading' => true, 'paperTrading' => false],
        ]);
        $future->state()->create([
            'status' => 'Running',
            'state' => 'LIVE_IN_POSITION',
            'quantity' => 1,
        ]);

        $fast2x = Fast2XConfig::create([
            'workspace_id' => $workspaceId,
            'symbol' => 'BTCUSDT',
            'paper_trading' => true,
            'enable_live_trading' => false,
            'settings' => config('fast2x.defaults'),
        ]);
        $fast2x->state()->create(['status' => 'Stopped', 'websocket_status' => 'Disconnected']);

        $updated = app(Fast2XService::class)->updateConfig($fast2x->fresh('state'), [
            'enable_live_trading' => true,
            'settings' => [
                'paperTrading' => false,
            ],
        ]);

        $this->assertTrue($updated->paper_trading);
        $this->assertFalse($updated->enable_live_trading);
    }

    public function test_fast2x_testnet_is_allowed_when_future_trade_live_is_active(): void
    {
        config(['fast2x.enable_live_trading' => true]);

        $workspaceId = '00000000-0000-0000-0000-000000000012';

        $future = FutureTradeBotConfig::create([
            'workspace_id' => $workspaceId,
            'paper_trading' => false,
            'enable_live_trading' => true,
            'enabled' => true,
            'settings' => ['liveTrading' => true, 'paperTrading' => false, 'forceTestnet' => false],
        ]);
        $future->state()->create([
            'status' => 'Running',
            'state' => 'LIVE_IN_POSITION',
            'quantity' => 1,
        ]);

        $fast2x = Fast2XConfig::create([
            'workspace_id' => $workspaceId,
            'symbol' => 'BTCUSDT',
            'paper_trading' => true,
            'enable_live_trading' => false,
            'settings' => config('fast2x.defaults'),
        ]);
        $fast2x->state()->create(['status' => 'Stopped', 'websocket_status' => 'Disconnected']);

        $updated = app(Fast2XService::class)->updateConfig($fast2x->fresh('state'), [
            'enable_live_trading' => true,
            'settings' => [
                'paperTrading' => false,
                'envMode' => 'testnet',
                'envTestnetEnabled' => true,
                'testnetApiKey' => 'test-key',
                'testnetApiSecret' => 'test-secret',
            ],
        ]);

        $this->assertFalse($updated->paper_trading);
        $this->assertTrue($updated->enable_live_trading);
        $this->assertSame('testnet', $updated->executionMode());
    }

    public function test_future_trade_live_is_downgraded_when_fast2x_live_is_active(): void
    {
        config(['future_trade.enable_live_trading' => true]);

        $workspaceId = '00000000-0000-0000-0000-000000000011';

        $fast2x = Fast2XConfig::create([
            'workspace_id' => $workspaceId,
            'symbol' => 'BTCUSDT',
            'paper_trading' => false,
            'enable_live_trading' => true,
            'settings' => array_merge(config('fast2x.defaults'), ['paperTrading' => false]),
        ]);
        $fast2x->state()->create([
            'status' => 'Running',
            'websocket_status' => 'Connected',
            'quantity' => 0.01,
        ]);

        $future = FutureTradeBotConfig::create([
            'workspace_id' => $workspaceId,
            'paper_trading' => true,
            'enable_live_trading' => false,
            'enabled' => false,
            'settings' => config('future_trade.defaults'),
        ]);
        $future->state()->create(['status' => 'Stopped', 'state' => 'IDLE']);

        $updated = app(FutureTradeBotService::class)->updateConfig($future->fresh('state'), [
            'settings' => [
                'liveTrading' => true,
                'paperTrading' => false,
            ],
        ]);

        $this->assertTrue($updated->paper_trading);
        $this->assertFalse($updated->enable_live_trading);
    }
}
