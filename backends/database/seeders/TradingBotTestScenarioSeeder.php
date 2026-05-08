<?php

namespace Database\Seeders;

use App\Models\Fast2XConfig;
use App\Models\FutureTradeBotConfig;
use App\Models\Workspace;
use Illuminate\Database\Seeder;

class TradingBotTestScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::query()->firstOrCreate(
            ['name' => 'Trading Bot Test Workspace'],
            ['timezone' => 'Asia/Colombo', 'settings' => json_encode(['seededFor' => 'trading-bot-smoke-tests'])]
        );

        $future = FutureTradeBotConfig::query()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'bot_name' => 'Future Trade Bot',
                'paper_trading' => true,
                'enable_live_trading' => false,
                'enabled' => false,
                'settings' => config('future_trade.defaults', []),
            ]
        );
        $future->state()->firstOrCreate([], [
            'status' => 'Stopped',
            'state' => 'IDLE',
            'connection_status' => 'Disconnected',
            'current_signal' => 'WAITING_FOR_SIGNAL',
            'trading_day' => now()->toDateString(),
        ]);

        $fast2x = Fast2XConfig::query()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'bot_name' => 'Fast2X',
                'symbol' => 'BTCUSDT',
                'paper_trading' => true,
                'enable_live_trading' => false,
                'settings' => config('fast2x.defaults', []),
            ]
        );
        $fast2x->state()->firstOrCreate([], [
            'status' => 'Stopped',
            'state' => 'IDLE',
            'websocket_status' => 'Disconnected',
        ]);
    }
}
