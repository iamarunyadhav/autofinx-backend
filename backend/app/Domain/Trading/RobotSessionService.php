<?php

namespace App\Domain\Trading;

use App\Models\QuickTrade;
use App\Models\RobotSession;
use Illuminate\Support\Str;
use RuntimeException;

class RobotSessionService
{
    public function __construct(
        private readonly TradingModuleSettingsService $settings,
        private readonly QuickTradeService $quickTrades,
        private readonly TradingMarketService $market,
        private readonly SignalPreviewService $signals,
        private readonly TimeframeRegistry $timeframes,
    ) {
    }

    public function create(string $workspaceId, array $payload, ?string $userId = null): RobotSession
    {
        $config = $this->settings->robotConfig($workspaceId);
        $state = $this->settings->tradingState($workspaceId);
        $timeframe = $this->timeframes->normalize((string) ($payload['timeframe'] ?? '1m'));
        $symbol = strtoupper((string) ($payload['symbol'] ?? ''));
        $baseStake = round((float) ($payload['base_stake'] ?? 0), 8);
        if ($symbol === '' || $baseStake <= 0) {
            throw new RuntimeException('Robot sessions require a symbol and a positive base stake.');
        }

        return RobotSession::create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'wallet_account_id' => $payload['wallet_account_id'] ?? null,
            'risk_profile_id' => $payload['risk_profile_id'] ?? null,
            'name' => (string) ($payload['name'] ?? "{$symbol} Robot"),
            'symbol' => $symbol,
            'asset_class' => strtoupper((string) ($this->settings->symbolMeta(
                $workspaceId,
                $symbol,
                (string) ($state['mode'] ?? 'paper'),
                (string) ($payload['provider'] ?? ($state['preferred_provider'] ?? 'binance'))
            )['asset_class'] ?? 'METALS')),
            'timeframe' => $timeframe,
            'expiry_seconds' => (int) ($payload['expiry_seconds'] ?? $this->settings->quickTradeConfig($workspaceId)['default_duration'] ?? 60),
            'indicator_set' => collect($payload['indicator_set'] ?? $config['default_indicators'] ?? ['RSI', 'MACD'])
                ->map(fn ($row) => strtoupper((string) $row))
                ->unique()
                ->values()
                ->all(),
            'strategy_profile' => (string) ($payload['strategy_profile'] ?? $config['default_strategy_profile'] ?? 'balanced'),
            'base_stake' => $baseStake,
            'profit_target' => $payload['profit_target'] ?? null,
            'loss_limit' => $payload['loss_limit'] ?? null,
            'max_consecutive_losses' => $payload['max_consecutive_losses'] ?? null,
            'max_active_trades' => (int) ($payload['max_active_trades'] ?? $config['max_active_trades'] ?? 2),
            'confidence_threshold' => (int) ($payload['confidence_threshold'] ?? $config['confidence_threshold'] ?? 60),
            'mode' => (string) ($state['mode'] ?? 'paper'),
            'provider_key' => strtolower((string) ($payload['provider'] ?? ($state['preferred_provider'] ?? 'binance'))),
            'integration_id' => $payload['integration_id'] ?? ($state['preferred_integration_id'] ?? null),
            'status' => 'STOPPED',
            'runtime_json' => [
                'notes' => sprintf('%s-mode robot session', strtoupper((string) ($state['mode'] ?? 'paper'))),
                'provider' => strtolower((string) ($payload['provider'] ?? ($state['preferred_provider'] ?? 'binance'))),
                'integration_id' => $payload['integration_id'] ?? ($state['preferred_integration_id'] ?? null),
                'execution_model' => ($state['mode'] ?? 'paper') === 'live' ? 'timed_futures' : 'paper_expiry',
            ],
        ]);
    }

    public function update(RobotSession $session, array $payload): RobotSession
    {
        $updates = [];
        foreach ([
            'name',
            'wallet_account_id',
            'risk_profile_id',
            'strategy_profile',
            'profit_target',
            'loss_limit',
            'max_consecutive_losses',
            'max_active_trades',
            'confidence_threshold',
            'provider_key',
            'integration_id',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }
        if (array_key_exists('timeframe', $payload)) {
            $updates['timeframe'] = $this->timeframes->normalize((string) $payload['timeframe']);
        }
        if (array_key_exists('expiry_seconds', $payload)) {
            $updates['expiry_seconds'] = (int) $payload['expiry_seconds'];
        }
        if (array_key_exists('base_stake', $payload)) {
            $updates['base_stake'] = round((float) $payload['base_stake'], 8);
        }
        if (array_key_exists('indicator_set', $payload)) {
            $updates['indicator_set'] = collect((array) $payload['indicator_set'])
                ->map(fn ($row) => strtoupper((string) $row))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }
        if (array_key_exists('symbol', $payload)) {
            $updates['symbol'] = strtoupper((string) $payload['symbol']);
            $updates['asset_class'] = strtoupper((string) ($this->settings->symbolMeta(
                $session->workspace_id,
                $updates['symbol'],
                (string) $session->mode,
                (string) ($payload['provider_key'] ?? $session->provider_key ?? 'binance')
            )['asset_class'] ?? 'METALS'));
        }

        $session->update($updates);

        return $this->syncExecutionContext($this->refreshPerformance($session));
    }

    public function start(RobotSession $session): RobotSession
    {
        if (! $this->settings->automationEnabled($session->workspace_id)) {
            throw new RuntimeException('Auto trading is disabled for this workspace. Enable it in Settings before starting robot sessions.');
        }

        $session = $this->syncExecutionContext($session, true);
        $session->status = 'RUNNING';
        $session->last_error = null;
        $session->save();

        return $session->fresh();
    }

    public function pause(RobotSession $session): RobotSession
    {
        $session->status = 'PAUSED';
        $session->save();

        return $session->fresh();
    }

    public function stop(RobotSession $session, ?string $reason = null): RobotSession
    {
        $session->status = 'STOPPED';
        if ($reason) {
            $session->last_error = $reason;
        }
        $session->save();

        return $session->fresh();
    }

    public function evaluateActiveSessions(?string $sessionId = null, int $limit = 50): array
    {
        $results = [];
        $query = RobotSession::query()
            ->where('status', 'RUNNING')
            ->when($sessionId, fn ($builder) => $builder->whereKey($sessionId))
            ->limit($limit);

        foreach ($query->get() as $session) {
            $results[] = $this->evaluateSession($session);
        }

        return $results;
    }

    public function evaluateSession(RobotSession $session): array
    {
        if (! $this->settings->automationEnabled($session->workspace_id)) {
            if ($session->status === 'RUNNING') {
                $session->status = 'PAUSED';
                $session->last_error = 'Auto trading is disabled for this workspace.';
                $session->save();
            }

            return ['session_id' => $session->id, 'status' => 'paused', 'action' => 'skipped'];
        }

        $session = $this->syncExecutionContext($this->refreshPerformance($session));

        if ($session->status !== 'RUNNING') {
            return ['session_id' => $session->id, 'status' => $session->status, 'action' => 'skipped'];
        }

        $cooldownSeconds = (int) ($this->settings->robotConfig($session->workspace_id)['cooldown_seconds'] ?? 15);
        if ($session->last_trade_at && now()->diffInSeconds($session->last_trade_at) < $cooldownSeconds) {
            return ['session_id' => $session->id, 'status' => 'cooldown', 'action' => 'skipped'];
        }

        $activeTrades = QuickTrade::where('robot_session_id', $session->id)
            ->where('status', 'open')
            ->count();
        if ($activeTrades >= (int) $session->max_active_trades) {
            return ['session_id' => $session->id, 'status' => 'active_limit', 'action' => 'skipped'];
        }

        $candles = $this->market->candles($session->symbol, $session->timeframe, 100, [
            'workspace_id' => $session->workspace_id,
            'mode' => $session->mode,
            'provider' => $session->provider_key,
            'integration_id' => $session->integration_id,
        ]);
        $signal = $this->signals->preview($candles, $session->timeframe, (array) $session->indicator_set);
        $session->last_signal_at = now();
        $session->runtime_json = array_merge((array) $session->runtime_json, [
            'latest_signal' => $signal,
        ]);
        $session->save();

        if (($signal['direction'] ?? 'neutral') === 'neutral' || (int) ($signal['confidence'] ?? 0) < (int) $session->confidence_threshold) {
            return ['session_id' => $session->id, 'status' => 'neutral', 'action' => 'skipped', 'signal' => $signal];
        }

        $trade = $this->quickTrades->place([
            'workspace_id' => $session->workspace_id,
            'wallet_account_id' => $session->wallet_account_id,
            'robot_session_id' => $session->id,
            'symbol' => $session->symbol,
            'side' => $signal['direction'],
            'stake_amount' => (float) $session->base_stake,
            'timeframe' => $session->timeframe,
            'expiry_seconds' => (int) $session->expiry_seconds,
            'mode' => strtolower((string) $session->mode),
            'source' => 'robot_trade',
            'indicator_set' => (array) $session->indicator_set,
            'signal_payload' => $signal,
            'provider' => $session->provider_key,
            'integration_id' => $session->integration_id,
        ], $session->user_id);

        $session->last_trade_at = now();
        $session->save();

        return [
            'session_id' => $session->id,
            'status' => 'placed',
            'action' => 'trade_opened',
            'trade_id' => $trade->id,
            'signal' => $signal,
        ];
    }

    public function refreshPerformance(?RobotSession $session): RobotSession
    {
        if (! $session) {
            throw new RuntimeException('Robot session not found.');
        }

        $history = QuickTrade::where('robot_session_id', $session->id)
            ->whereIn('status', ['won', 'lost', 'draw'])
            ->orderBy('settled_at')
            ->get();

        $totalPnl = (float) $history->sum('profit_loss');
        $winCount = $history->where('status', 'won')->count();
        $lossCount = $history->where('status', 'lost')->count();
        $consecutiveLosses = 0;
        foreach ($history->reverse() as $trade) {
            if ($trade->status !== 'lost') {
                break;
            }
            $consecutiveLosses++;
        }

        $session->fill([
            'total_pnl' => $totalPnl,
            'win_count' => $winCount,
            'loss_count' => $lossCount,
            'consecutive_losses' => $consecutiveLosses,
        ]);

        if ($session->status === 'RUNNING') {
            if ($session->profit_target !== null && $totalPnl >= (float) $session->profit_target) {
                $session->status = 'STOPPED';
                $session->last_error = 'Profit target reached.';
            }
            if ($session->loss_limit !== null && $totalPnl <= -1 * abs((float) $session->loss_limit)) {
                $session->status = 'STOPPED';
                $session->last_error = 'Loss limit reached.';
            }
            if ($session->max_consecutive_losses !== null && $consecutiveLosses >= (int) $session->max_consecutive_losses) {
                $session->status = 'STOPPED';
                $session->last_error = 'Max consecutive losses reached.';
            }
        }

        $session->save();

        return $session->fresh();
    }

    public function statusPayload(RobotSession $session): array
    {
        $session = $this->syncExecutionContext($this->refreshPerformance($session));

        return [
            'session' => $session,
            'active_trades' => QuickTrade::where('robot_session_id', $session->id)->where('status', 'open')->count(),
            'recent_trades' => QuickTrade::where('robot_session_id', $session->id)
                ->where('status', '!=', 'open')
                ->orderByDesc('settled_at')
                ->limit(10)
                ->get(),
        ];
    }

    private function syncExecutionContext(RobotSession $session, bool $requireReady = false): RobotSession
    {
        $state = $this->settings->tradingState($session->workspace_id);
        $provider = strtolower((string) ($session->provider_key ?: ($state['preferred_provider'] ?? 'binance')));
        $integrationId = $session->integration_id ?: ($state['preferred_integration_id'] ?? null);
        $mode = strtolower((string) ($state['mode'] ?? $session->mode ?? 'paper'));

        if ($requireReady && $mode === 'live' && ! ($state['live_ready'] ?? false) && ! $integrationId) {
            throw new RuntimeException((string) ($state['live_ready_reason'] ?? 'Live trading is not ready for this workspace.'));
        }

        $session->mode = $mode;
        $session->provider_key = $provider;
        $session->integration_id = $integrationId;
        $session->runtime_json = array_merge((array) ($session->runtime_json ?? []), [
            'provider' => $provider,
            'integration_id' => $integrationId,
            'execution_model' => $mode === 'live' ? 'timed_futures' : 'paper_expiry',
        ]);
        $session->save();

        return $session->fresh();
    }
}
