<?php

namespace App\Domain\Fast2X;

use App\Models\Fast2XConfig;
use App\Models\Fast2XState;

class BotStateService
{
    public function __construct(private readonly DecisionLogService $logs) {}

    public function getOrCreate(Fast2XConfig $config): Fast2XState
    {
        return $config->state()->firstOrCreate([], [
            'state' => Fast2XState::IDLE,
            'status' => 'Stopped',
            'websocket_status' => 'Disconnected',
            'decision' => 'WAITING_FOR_SIGNAL',
        ]);
    }

    public function start(Fast2XConfig $config): Fast2XState
    {
        $state = $this->getOrCreate($config);
        $state->update([
            'state' => Fast2XState::WAITING_FOR_SIGNAL,
            'status' => 'Running',
            'websocket_status' => 'Connected',
            'decision' => 'WAITING_FOR_SIGNAL',
        ]);
        $this->logs->log($config->fresh('state'), 'BOT_STARTED', ['reason' => 'Paper trading default. Trading involves risk. This bot does not guarantee profit.']);
        return $state->fresh();
    }

    public function pause(Fast2XConfig $config, string $reason = 'operator'): Fast2XState
    {
        $state = $this->getOrCreate($config);
        $state->update(['state' => Fast2XState::PAUSED, 'status' => 'Paused', 'decision' => 'TRADING_PAUSED']);
        $this->logs->log($config->fresh('state'), 'BOT_PAUSED', ['reason' => $reason]);
        return $state->fresh();
    }

    public function stop(Fast2XConfig $config, string $reason = 'operator'): Fast2XState
    {
        $state = $this->getOrCreate($config);
        $state->update([
            'state' => Fast2XState::STOPPED,
            'status' => 'Stopped',
            'websocket_status' => 'Disconnected',
            'decision' => 'WAITING_FOR_SIGNAL',
            'order_in_progress' => false,
        ]);
        $this->logs->log($config->fresh('state'), 'BOT_STOPPED', ['reason' => $reason]);
        return $state->fresh();
    }

    public function emergencyStop(Fast2XConfig $config): Fast2XState
    {
        $settings = $config->mergedSettings();
        $settings['emergencyStop'] = true;
        $config->update(['settings' => $settings, 'paper_trading' => true, 'enable_live_trading' => false]);

        $state = $this->stop($config, 'emergency_stop');
        $this->logs->log($config->fresh('state'), 'EMERGENCY_STOP_TRIGGERED', ['reason' => 'Trading halted immediately']);
        return $state;
    }

    public function resetPaper(Fast2XConfig $config): Fast2XState
    {
        $config->trades()->delete();
        $state = $this->getOrCreate($config);
        $state->update([
            'state' => Fast2XState::IDLE,
            'status' => 'Stopped',
            'entry_price' => null,
            'current_price' => null,
            'quantity' => 0,
            'current_peak' => null,
            'current_bottom' => null,
            'unrealized_pnl' => 0,
            'unrealized_pnl_percent' => 0,
            'realized_pnl' => 0,
            'net_pnl' => 0,
            'net_pnl_percent' => 0,
            'daily_profit' => 0,
            'daily_loss' => 0,
            'wins' => 0,
            'losses' => 0,
            'trade_count_today' => 0,
            'order_in_progress' => false,
        ]);
        $this->logs->log($config->fresh('state'), 'PAPER_TRADING_RESET', ['reason' => 'Paper ledger and runtime PnL reset']);
        return $state->fresh();
    }
}
