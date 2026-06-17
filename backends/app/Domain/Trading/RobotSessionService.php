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
        private readonly CandleDirectionBotService $candleBot,
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
            'bot_mode' => in_array(($payload['bot_mode'] ?? 'timed_expiry'), ['candle_direction', 'timed_expiry'], true)
                ? (string) $payload['bot_mode']
                : 'timed_expiry',
            'stop_loss_pct' => round((float) ($payload['stop_loss_pct'] ?? 20.0), 4),
            'take_profit_pct' => isset($payload['take_profit_pct']) ? round((float) $payload['take_profit_pct'], 4) : null,
            'multi_symbol' => (bool) ($payload['multi_symbol'] ?? false),
            'symbol_list' => isset($payload['symbol_list'])
                ? collect((array) $payload['symbol_list'])->map(fn ($s) => strtoupper((string) $s))->filter()->unique()->values()->all()
                : null,
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
            'bot_mode',
            'stop_loss_pct',
            'take_profit_pct',
            'multi_symbol',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }
        if (array_key_exists('symbol_list', $payload)) {
            $updates['symbol_list'] = collect((array) $payload['symbol_list'])
                ->map(fn ($s) => strtoupper((string) $s))
                ->filter()
                ->unique()
                ->values()
                ->all();
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

    /**
     * Delete a robot session. Any open candle-direction position is force-closed
     * first so we never orphan a live exchange position. Settled quick trades are
     * preserved (their robot_session_id is nulled by the FK).
     *
     * @return array<string, mixed>
     */
    public function delete(RobotSession $session): array
    {
        $id = $session->id;

        if ($session->active_side) {
            try {
                $this->forceClose($session);
                $session = $session->fresh() ?? $session;
            } catch (\Throwable $e) {
                // Surface the failure rather than deleting with an open position.
                throw new RuntimeException('Could not close the open position before delete: ' . $e->getMessage());
            }
        }

        $session->delete();

        return ['session_id' => $id, 'status' => 'deleted'];
    }

    public function pause(RobotSession $session): RobotSession
    {
        $session->status = 'PAUSED';
        $session->save();

        return $session->fresh();
    }

    /**
     * Reset a session that was halted by a loss limit, profit target, consecutive
     * losses, or an error. Clears the error, baselines the stats (PnL / win-loss /
     * streak start fresh from now — past deals stay in history but no longer count
     * toward the limits), and leaves it STOPPED and ready to Start again.
     *
     * @return array<string, mixed>
     */
    public function reset(RobotSession $session): array
    {
        $session->last_error = null;
        $session->status = $session->status === 'RUNNING' ? 'RUNNING' : 'STOPPED';
        $session->runtime_json = array_merge((array) $session->runtime_json, [
            'stats_reset_at' => now()->toIso8601String(),
            'last_reset_at' => now()->toIso8601String(),
        ]);
        $session->save();

        // Recompute against the fresh baseline (post-reset history is empty -> 0).
        $session = $this->refreshPerformance($session);

        return [
            'session_id' => $session->id,
            'status' => 'reset',
            'total_pnl' => (float) $session->total_pnl,
            'win_count' => (int) $session->win_count,
            'loss_count' => (int) $session->loss_count,
            'message' => 'Stats baselined; loss limit / error cleared. You can start again.',
        ];
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

        if (($session->bot_mode ?? 'timed_expiry') === 'candle_direction') {
            return $this->evaluateCandleDirectionSession($session);
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

    /**
     * Candle Direction Bot evaluation path: open on a directional signal, then
     * ride the move with a trailing stop until the bot says CLOSE.
     *
     * @return array<string, mixed>
     */
    public function evaluateCandleDirectionSession(RobotSession $session): array
    {
        // Sync first: if the position was closed externally on the exchange,
        // clear our state before deciding anything.
        if ($this->reconcileExternalClose($session)) {
            $session = $session->fresh() ?? $session;
        }

        $candles = $this->market->candles($session->symbol, $session->timeframe, 100, [
            'workspace_id' => $session->workspace_id,
            'mode' => $session->mode,
            'provider' => $session->provider_key,
            'integration_id' => $session->integration_id,
        ]);

        if ($candles === []) {
            return ['session_id' => $session->id, 'status' => 'no_data', 'action' => 'skipped'];
        }

        $decision = $this->candleBot->evaluateCandle($session, $candles);
        $latest = $candles[array_key_last($candles)];
        $closePrice = (float) ($latest['close'] ?? 0);

        $session->last_signal_at = now();
        $session->runtime_json = array_merge((array) $session->runtime_json, [
            'latest_decision' => $decision,
            'last_close_price' => $closePrice,
        ]);

        $action = $decision['action'];

        if ($action === 'BUY' || $action === 'SELL') {
            $trade = $this->quickTrades->place([
                'workspace_id' => $session->workspace_id,
                'wallet_account_id' => $session->wallet_account_id,
                'robot_session_id' => $session->id,
                'symbol' => $session->symbol,
                'side' => strtolower($action),
                'stake_amount' => (float) $session->base_stake,
                'timeframe' => $session->timeframe,
                'expiry_seconds' => (int) $session->expiry_seconds,
                'mode' => strtolower((string) $session->mode),
                'source' => 'robot_candle_direction',
                'indicator_set' => (array) $session->indicator_set,
                'signal_payload' => $decision['signal'] ?? $decision,
                'provider' => $session->provider_key,
                'integration_id' => $session->integration_id,
            ], $session->user_id);

            $session->active_side = $action;
            $session->active_entry_price = (float) ($trade->entry_price ?? $closePrice);
            $session->active_since = now();
            $session->peak_profit = 0;
            $session->last_trade_at = now();
            $session->save();

            return [
                'session_id' => $session->id,
                'status' => 'opened',
                'action' => 'trade_opened',
                'side' => $action,
                'trade_id' => $trade->id,
                'decision' => $decision,
            ];
        }

        if ($action === 'CLOSE') {
            $closed = $this->closeActiveCandleTrade($session);
            $session->active_side = null;
            $session->active_entry_price = null;
            $session->active_since = null;
            $session->peak_profit = 0;
            $session->last_trade_at = now();
            $session->save();
            $this->refreshPerformance($session);

            return [
                'session_id' => $session->id,
                'status' => 'closed',
                'action' => 'trade_closed',
                'trade_id' => $closed?->id,
                'decision' => $decision,
            ];
        }

        // HOLD: keep peak excursion fresh while in a position.
        if ($session->active_side) {
            $entry = (float) $session->active_entry_price;
            $excursion = $this->candleBot->favourableExcursion((string) $session->active_side, $entry, $closePrice);
            $session->peak_profit = max((float) $session->peak_profit, $excursion);
        }
        $session->save();

        return [
            'session_id' => $session->id,
            'status' => $session->active_side ? 'holding' : 'waiting',
            'action' => 'hold',
            'decision' => $decision,
        ];
    }

    /**
     * Real-time stats for the Candle Direction Bot: current price, live position,
     * unrealised PnL, peak profit and the trailing-stop level.
     *
     * @return array<string, mixed>
     */
    public function liveStats(RobotSession $session): array
    {
        $session = $this->refreshPerformance($session);

        // Near-real-time external-close sync for the UI, throttled to avoid
        // hammering the exchange (positionRisk) on every 1s poll.
        if (strtolower((string) $session->mode) === 'live' && $session->active_side) {
            $lastRecon = ((array) $session->runtime_json)['last_recon_at'] ?? null;
            $due = ! $lastRecon || now()->diffInSeconds(\Illuminate\Support\Carbon::parse($lastRecon)) >= 15;
            if ($due) {
                $changed = $this->reconcileExternalClose($session);
                $session->runtime_json = array_merge((array) $session->runtime_json, ['last_recon_at' => now()->toIso8601String()]);
                $session->save();
                if ($changed) {
                    $session = $session->fresh() ?? $session;
                }
            }
        }

        $quote = $this->market->latestQuote($session->symbol, $session->timeframe, [
            'workspace_id' => $session->workspace_id,
            'mode' => $session->mode,
            'provider' => $session->provider_key,
            'integration_id' => $session->integration_id,
        ]);
        $currentPrice = (float) ($quote['price'] ?? 0);

        $position = null;
        if ($session->active_side && $session->active_entry_price !== null) {
            $side = (string) $session->active_side;
            $entry = (float) $session->active_entry_price;
            $excursion = $this->candleBot->favourableExcursion($side, $entry, $currentPrice);
            $peakExcursion = max((float) $session->peak_profit, $excursion);
            $stopPrice = $this->candleBot->computeTrailingStop($entry, $peakExcursion, (float) $session->stop_loss_pct, $side);
            $stake = (float) $session->base_stake;

            $position = [
                'side' => $side,
                'entry_price' => $entry,
                'current_price' => $currentPrice,
                'stake' => $stake,
                'unrealized_pnl' => $entry > 0 ? round($excursion / $entry * $stake, 8) : 0.0,
                'unrealized_pct' => $entry > 0 ? round($excursion / $entry * 100, 4) : 0.0,
                'peak_excursion' => round($peakExcursion, 8),
                'peak_pct' => $entry > 0 ? round($peakExcursion / $entry * 100, 4) : 0.0,
                'stop_price' => round($stopPrice, 8),
                'active_since' => $session->active_since?->toIso8601String(),
                'seconds_in_trade' => $session->active_since ? (int) abs(now()->diffInSeconds($session->active_since)) : 0,
            ];
        }

        $closed = (int) $session->win_count + (int) $session->loss_count;

        return [
            'session_id' => $session->id,
            'status' => $session->status,
            'symbol' => $session->symbol,
            'timeframe' => $session->timeframe,
            'bot_mode' => $session->bot_mode,
            'current_price' => $currentPrice,
            'position' => $position,
            'performance' => [
                'total_pnl' => (float) $session->total_pnl,
                'win_count' => (int) $session->win_count,
                'loss_count' => (int) $session->loss_count,
                'win_rate' => $closed > 0 ? round((int) $session->win_count / $closed * 100, 2) : 0.0,
            ],
            'last_decision' => ((array) $session->runtime_json)['latest_decision'] ?? null,
            'as_of' => now()->toIso8601String(),
        ];
    }

    /**
     * Manually close the active candle-direction position.
     *
     * @return array<string, mixed>
     */
    public function forceClose(RobotSession $session): array
    {
        $closed = $this->closeActiveCandleTrade($session);

        $session->active_side = null;
        $session->active_entry_price = null;
        $session->active_since = null;
        $session->peak_profit = 0;
        $session->last_trade_at = now();
        $session->runtime_json = array_merge((array) $session->runtime_json, [
            'latest_decision' => ['action' => 'CLOSE', 'reason' => 'Manual force-close.', 'confidence' => 1.0],
        ]);
        $session->save();
        $this->refreshPerformance($session);

        return [
            'session_id' => $session->id,
            'status' => 'closed',
            'action' => $closed ? 'trade_closed' : 'no_active_trade',
            'trade_id' => $closed?->id,
        ];
    }

    /**
     * Sync our state when a live position was closed externally (manually on
     * Binance, by exchange TP/SL, or liquidation). If the exchange shows the
     * symbol flat but we still think we hold a position, settle our open trade
     * (without sending another order) and clear the active state.
     *
     * @return bool true if a reconciliation (external close) was applied
     */
    public function reconcileExternalClose(RobotSession $session): bool
    {
        if (strtolower((string) $session->mode) !== 'live' || ! $session->active_side) {
            return false;
        }

        try {
            $integration = app(TradingIntegrationResolver::class)->resolve(
                $session->workspace_id,
                $session->provider_key ?: 'binance',
                $session->integration_id,
            );
            $position = app(\App\Domain\Trading\Providers\LiveTradeProviderRegistry::class)
                ->for($session->provider_key ?: 'binance')
                ->positionFor($integration, $session->symbol);
        } catch (\Throwable) {
            return false; // Can't verify right now — leave state untouched.
        }

        $amount = (float) ($position['positionAmt'] ?? 0);
        $price = (float) ($position['markPrice'] ?? 0);
        if ($price <= 0) {
            $price = (float) ($this->market->latestQuote($session->symbol, $session->timeframe, [
                'workspace_id' => $session->workspace_id,
                'mode' => $session->mode,
                'provider' => $session->provider_key,
                'integration_id' => $session->integration_id,
            ])['price'] ?? 0);
        }

        // Treat exactly-zero (manual "Close") and sub-$10 un-closable lot dust as
        // flat; a real bot position is always well above the min notional.
        $notional = abs($amount) * max($price, 0);
        if (abs($amount) > 1e-9 && $notional >= 10.0) {
            return false; // Still a real position on the exchange.
        }

        // Flat on the exchange -> settle our open trade as already closed.
        $trade = QuickTrade::where('robot_session_id', $session->id)
            ->where('status', 'open')
            ->orderByDesc('entry_at')
            ->first();

        if ($trade) {
            $entry = (float) $trade->entry_price;
            $exit = $price > 0 ? $price : $entry;
            $qty = (float) ($trade->quantity ?: 0);
            $pnl = strtolower((string) $trade->side) === 'buy' ? ($exit - $entry) * $qty : ($entry - $exit) * $qty;
            $trade->update([
                'exit_price' => $exit,
                'status' => $pnl >= 0 ? 'won' : 'lost',
                'profit_loss' => round($pnl, 8),
                'settled_at' => now(),
            ]);
        }

        $session->active_side = null;
        $session->active_entry_price = null;
        $session->active_since = null;
        $session->peak_profit = 0;
        $session->runtime_json = array_merge((array) $session->runtime_json, [
            'latest_decision' => ['action' => 'CLOSE', 'reason' => 'Position closed externally on the exchange — synced.', 'confidence' => 1.0],
        ]);
        $session->save();
        $this->refreshPerformance($session);

        return true;
    }

    /**
     * Close the open quick trade backing the candle-direction position, if any.
     */
    private function closeActiveCandleTrade(RobotSession $session): ?QuickTrade
    {
        $trade = QuickTrade::where('robot_session_id', $session->id)
            ->where('status', 'open')
            ->orderByDesc('entry_at')
            ->first();

        if (! $trade) {
            return null;
        }

        return $this->quickTrades->settleTrade($trade);
    }

    public function refreshPerformance(?RobotSession $session): RobotSession
    {
        if (! $session) {
            throw new RuntimeException('Robot session not found.');
        }

        // A reset baselines stats: only trades settled after stats_reset_at count
        // toward PnL / win-loss / streak and the profit-target / loss-limit gates.
        $resetAt = ((array) $session->runtime_json)['stats_reset_at'] ?? null;

        $history = QuickTrade::where('robot_session_id', $session->id)
            ->whereIn('status', ['won', 'lost', 'draw'])
            ->when($resetAt, fn ($q) => $q->where('settled_at', '>', $resetAt))
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
