<?php

namespace App\Domain\Trading;

use App\Models\QuickTrade;
use App\Models\RobotSession;
use App\Domain\FutureTrade\FutureTradeAuditService;
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
                ? (string) ($payload['bot_mode'] ?? 'timed_expiry')
                : 'timed_expiry',
            'stop_loss_pct' => round((float) ($payload['stop_loss_pct'] ?? 20.0), 4),
            'take_profit_pct' => isset($payload['take_profit_pct']) ? round((float) $payload['take_profit_pct'], 4) : null,
            'cooldown_seconds' => (int) ($payload['cooldown_seconds'] ?? 300),
            'daily_loss_limit' => isset($payload['daily_loss_limit']) ? round((float) $payload['daily_loss_limit'], 8) : null,
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
            'cooldown_seconds',
            'daily_loss_limit',
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
            // Clear daily loss + cooldown so bot can trade again immediately.
            'daily_loss_date' => now()->utc()->toDateString(),
            'daily_loss_total' => 0.0,
            'daily_loss_hit' => false,
            'cooldown_until' => null,
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

    /**
     * Reset ONLY the daily loss counter + cooldown — does NOT touch PnL history or
     * win/loss stats. Lets the user resume trading on the same day without waiting
     * for UTC midnight.
     *
     * @return array<string, mixed>
     */
    public function resetDailyLoss(RobotSession $session): array
    {
        $session->runtime_json = array_merge((array) $session->runtime_json, [
            'daily_loss_date' => now()->utc()->toDateString(),
            'daily_loss_total' => 0.0,
            'daily_loss_hit' => false,
            'cooldown_until' => null,
        ]);
        if ($session->last_error && (
            str_contains((string) $session->last_error, 'Daily loss') ||
            str_contains((string) $session->last_error, 'cooldown')
        )) {
            $session->last_error = null;
        }
        $session->save();

        return [
            'session_id' => $session->id,
            'status' => 'daily_loss_reset',
            'message' => 'Daily loss counter cleared. Trading resumes immediately.',
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
        // Auto-resume sessions that were paused yesterday due to consecutive losses.
        // A new UTC day means fresh daily limits — let them trade again.
        if (! $sessionId) {
            $todayUtc = now()->utc()->toDateString();
            RobotSession::where('status', 'PAUSED')
                ->where(fn ($q) => $q
                    ->where('last_error', 'LIKE', '%consecutive losses%')
                    ->orWhere('last_error', 'LIKE', '%Auto-paused%')
                )
                ->whereDate('updated_at', '<', $todayUtc)
                ->each(function (RobotSession $s) use ($todayUtc) {
                    $s->status = 'RUNNING';
                    $s->last_error = null;
                    $s->consecutive_losses = 0;
                    $s->signal_streak = 0;
                    $s->last_signal_direction = null;
                    $s->runtime_json = array_merge((array) $s->runtime_json, [
                        'daily_loss_date' => $todayUtc,
                        'daily_loss_total' => 0.0,
                        'daily_loss_hit' => false,
                        'cooldown_until' => null,
                        'auto_paused_at' => null,
                        'auto_pause_reason' => null,
                    ]);
                    $s->save();
                });
        }

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

        // ── Volatility gate ────────────────────────────────────────────────
        $volatility = $this->computeVolatilityGate($candles, $session->timeframe);
        if (! $volatility['tradeable']) {
            $session->runtime_json = array_merge((array) $session->runtime_json, [
                'volatility' => $volatility,
                'last_evaluation_at' => now()->toIso8601String(),
            ]);
            $session->save();
            return ['session_id' => $session->id, 'status' => 'volatility_block', 'action' => 'skipped', 'reason' => $volatility['reason']];
        }

        $signal = $this->signals->preview($candles, $session->timeframe, (array) $session->indicator_set);
        $session->last_signal_at = now();

        // ── Signal stability gate ──────────────────────────────────────────
        // Require the same direction on 2 consecutive evaluations before entering.
        $direction = (string) ($signal['direction'] ?? 'neutral');
        if ($direction !== 'neutral' && (int) ($signal['confidence'] ?? 0) >= (int) $session->confidence_threshold) {
            if ($session->last_signal_direction === $direction) {
                $session->signal_streak = (int) $session->signal_streak + 1;
            } else {
                $session->signal_streak = 1;
                $session->last_signal_direction = $direction;
                $session->last_signal_direction_at = now();
            }
            $session->runtime_json = array_merge((array) $session->runtime_json, [
                'latest_signal' => $signal,
                'volatility' => $volatility,
            ]);
            $session->save();

            if ($session->signal_streak < 2) {
                return ['session_id' => $session->id, 'status' => 'signal_building', 'action' => 'skipped', 'streak' => 1, 'direction' => $direction];
            }
            // Confirmed — reset streak so it doesn't fire repeatedly on same candle.
            $session->signal_streak = 0;
            $session->last_signal_direction = null;
            $session->save();
        } else {
            // No signal or below threshold: clear streak.
            if ($session->signal_streak > 0 || $session->last_signal_direction !== null) {
                $session->signal_streak = 0;
                $session->last_signal_direction = null;
            }
        }

        $session->runtime_json = array_merge((array) $session->runtime_json, [
            'latest_signal' => $signal,
            'volatility' => $volatility,
        ]);
        $session->save();

        if ($direction === 'neutral' || (int) ($signal['confidence'] ?? 0) < (int) $session->confidence_threshold) {
            return ['session_id' => $session->id, 'status' => 'neutral', 'action' => 'skipped', 'signal' => $signal];
        }

        // ── ATR-based position sizing ──────────────────────────────────────
        $atrAvg = $this->computeAtrAvgPct($candles);
        $atrNow = $volatility['atr_pct'];
        $atrRatio = ($atrAvg > 0 && $atrNow > 0) ? min(1.5, max(0.5, $atrAvg / $atrNow)) : 1.0;
        $adjustedStake = round((float) $session->base_stake * $atrRatio, 2);

        $trade = $this->quickTrades->place([
            'workspace_id' => $session->workspace_id,
            'wallet_account_id' => $session->wallet_account_id,
            'robot_session_id' => $session->id,
            'symbol' => $session->symbol,
            'side' => $signal['direction'],
            'stake_amount' => $adjustedStake,
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
        // ── Daily loss limit guard ──────────────────────────────────────────
        $todayUtc = now()->utc()->toDateString();
        $rt = (array) $session->runtime_json;
        $dailyDate = (string) ($rt['daily_loss_date'] ?? '');
        $dailyTotal = (float) ($rt['daily_loss_total'] ?? 0);
        $dailyHit = (bool) ($rt['daily_loss_hit'] ?? false);

        // Midnight reset: new calendar day clears the daily counters.
        if ($dailyDate !== $todayUtc) {
            $dailyTotal = 0.0;
            $dailyHit = false;
            $rt = array_merge($rt, [
                'daily_loss_date' => $todayUtc,
                'daily_loss_total' => 0.0,
                'daily_loss_hit' => false,
            ]);
            $session->runtime_json = $rt;
            $session->save();
        }

        if ($dailyHit && $session->daily_loss_limit !== null) {
            $session->last_error = sprintf(
                'Daily loss limit $%.2f hit (%s). Resumes tomorrow (UTC midnight).',
                (float) $session->daily_loss_limit,
                $todayUtc
            );
            $session->save();
            return ['session_id' => $session->id, 'status' => 'daily_limit_hit', 'action' => 'skipped'];
        }

        // ── Post-loss cooldown guard ────────────────────────────────────────
        $cooldownUntil = $rt['cooldown_until'] ?? null;
        if ($cooldownUntil && now()->lt(\Illuminate\Support\Carbon::parse($cooldownUntil))) {
            $secsLeft = (int) now()->diffInSeconds(\Illuminate\Support\Carbon::parse($cooldownUntil));
            return [
                'session_id' => $session->id,
                'status' => 'cooldown',
                'action' => 'skipped',
                'cooldown_seconds_left' => $secsLeft,
            ];
        }

        // ── Clear stale error once we are past cooldown / daily reset ───────
        if ($session->last_error && str_contains((string) $session->last_error, 'cooldown')) {
            $session->last_error = null;
            $session->save();
        }

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

        // ── Volatility gate (new entries only — skip if position is active) ─
        $volatility = ['tradeable' => true, 'reason' => 'position_active', 'atr_pct' => 0.0, 'bb_width_pct' => 0.0];
        if (! $session->active_side) {
            $volatility = $this->computeVolatilityGate($candles, $session->timeframe);
            if (! $volatility['tradeable']) {
                // Clear any stale signal streak when market is flat.
                if ($session->signal_streak > 0 || $session->last_signal_direction !== null) {
                    $session->signal_streak = 0;
                    $session->last_signal_direction = null;
                }
                $session->runtime_json = array_merge((array) $session->runtime_json, [
                    'volatility' => $volatility,
                    'last_evaluation_at' => now()->toIso8601String(),
                ]);
                $session->save();
                return ['session_id' => $session->id, 'status' => 'volatility_block', 'action' => 'skipped', 'reason' => $volatility['reason']];
            }
        }

        $decision = $this->candleBot->evaluateCandle($session, $candles);
        $latest = $candles[array_key_last($candles)];
        $closePrice = (float) ($latest['close'] ?? 0);
        app(FutureTradeAuditService::class)->recordRobotDecision($session, $candles, $decision);

        $totalLossLimit = $session->loss_limit !== null
            ? abs((float) $session->loss_limit)
            : (float) config('future_trade.robot.total_loss_limit_usdt', 0);
        if ($totalLossLimit > 0) {
            $unrealized = 0.0;
            if ($session->active_side && (float) $session->active_entry_price > 0 && $closePrice > 0) {
                $direction = strtoupper((string) $session->active_side) === 'SELL' ? -1 : 1;
                $unrealized = ($closePrice - (float) $session->active_entry_price) * $direction * (float) $session->base_stake;
            }
            $totalLoss = max(0.0, -1 * ((float) $session->total_pnl + $unrealized));
            if ($totalLoss >= $totalLossLimit && (bool) config('future_trade.robot.stop_after_total_loss', true)) {
                if ($session->active_side) {
                    try {
                        $this->forceClose($session);
                    } catch (\Throwable $e) {
                        $session->last_error = 'ROBOT_STOPPED_TOTAL_LOSS close failed: '.$e->getMessage();
                    }
                }
                $session->status = 'STOPPED';
                $session->last_error = 'ROBOT_STOPPED_TOTAL_LOSS';
                $session->runtime_json = array_merge((array) $session->runtime_json, [
                    'total_loss_limit_hit' => true,
                    'total_loss_limit_usdt' => $totalLossLimit,
                    'total_loss_observed_usdt' => round($totalLoss, 8),
                    'stopped_at' => now()->toIso8601String(),
                ]);
                $session->save();

                return [
                    'session_id' => $session->id,
                    'status' => 'ROBOT_STOPPED_TOTAL_LOSS',
                    'action' => 'stopped',
                    'total_loss' => round($totalLoss, 8),
                    'limit' => $totalLossLimit,
                ];
            }
        }

        $session->last_signal_at = now();

        // Persist signal analysis (pattern, momentum, continuation) for UI display.
        $signalAnalysis = $decision['signal'] ?? null;
        $analysisSnapshot = $signalAnalysis ? [
            'pattern' => $signalAnalysis['pattern'] ?? null,
            'pattern_type' => $signalAnalysis['pattern_type'] ?? null,
            'trend_bias' => $signalAnalysis['trend_bias'] ?? null,
            'trend_streak' => $signalAnalysis['trend_streak'] ?? null,
            'momentum_score' => $signalAnalysis['momentum_score'] ?? null,
            'continuation_pct' => $signalAnalysis['continuation_pct'] ?? null,
            'confidence' => $signalAnalysis['confidence'] ?? null,
            'side' => $signalAnalysis['side'] ?? null,
        ] : null;

        $session->runtime_json = array_merge((array) $session->runtime_json, [
            'latest_decision' => array_merge($decision, [
                'pattern' => $signalAnalysis['pattern'] ?? ($decision['pattern'] ?? null),
                'trend_bias' => $signalAnalysis['trend_bias'] ?? null,
                'momentum_score' => $signalAnalysis['momentum_score'] ?? null,
                'continuation_pct' => $signalAnalysis['continuation_pct'] ?? null,
            ]),
            'latest_signal_analysis' => $analysisSnapshot,
            'last_close_price' => $closePrice,
            'volatility' => $volatility,
            'last_evaluation_at' => now()->toIso8601String(),
        ]);

        $action = $decision['action'];

        if ($action === 'BUY' || $action === 'SELL') {
            $openDirection = strtolower($action);
            // Track direction streak for telemetry only (no longer gates entry)
            if ($session->last_signal_direction === $openDirection) {
                $session->signal_streak = (int) $session->signal_streak + 1;
            } else {
                $session->signal_streak = 1;
                $session->last_signal_direction = $openDirection;
                $session->last_signal_direction_at = now();
            }

            // Reset streak after entry
            $session->signal_streak = 0;
            $session->last_signal_direction = null;

            // ── ATR-based position sizing ──────────────────────────────────
            $atrAvg = $this->computeAtrAvgPct($candles);
            $atrNow = $volatility['atr_pct'];
            $atrRatio = ($atrAvg > 0 && $atrNow > 0) ? min(1.5, max(0.5, $atrAvg / $atrNow)) : 1.0;
            $adjustedStake = round((float) $session->base_stake * $atrRatio, 2);

            $trade = $this->quickTrades->place([
                'workspace_id' => $session->workspace_id,
                'wallet_account_id' => $session->wallet_account_id,
                'robot_session_id' => $session->id,
                'symbol' => $session->symbol,
                'side' => $openDirection,
                'stake_amount' => $adjustedStake,
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
            // Capture PnL snapshot BEFORE close so we can measure the trade result.
            $pnlBefore = (float) $session->total_pnl;
            $entrySide = (string) $session->active_side;
            $entryPrice = (float) $session->active_entry_price;
            $stake = (float) $session->base_stake;

            $closed = $this->closeActiveCandleTrade($session);
            $session->active_side = null;
            $session->active_entry_price = null;
            $session->active_since = null;
            $session->peak_profit = 0;
            $session->last_trade_at = now();
            $session->save();
            $this->refreshPerformance($session);

            // Measure the realised PnL of this specific trade.
            $session = $session->fresh() ?? $session;
            $pnlAfter = (float) $session->total_pnl;
            $tradePnl = $pnlAfter - $pnlBefore;

            // ── Cooldown + daily loss tracking ──────────────────────────────
            $rt2 = (array) $session->runtime_json;
            $extraRuntime = [];

            if ($tradePnl < 0) {
                $absLoss = abs($tradePnl);
                $consecutiveLosses = (int) $session->consecutive_losses; // fresh after refreshPerformance

                // ── Auto-pause after 3+ consecutive losses ─────────────────
                if ($consecutiveLosses >= 3) {
                    $session->status = 'PAUSED';
                    $session->last_error = sprintf(
                        'Auto-paused after %d consecutive losses. Resumes at UTC midnight or manual reset.',
                        $consecutiveLosses
                    );
                    $extraRuntime['auto_paused_at'] = now()->toIso8601String();
                    $extraRuntime['auto_pause_reason'] = 'consecutive_losses';
                    $extraRuntime['cooldown_until'] = null;
                } else {
                    // ── Adaptive cooldown: doubles with each consecutive loss ─
                    $baseCooldown = max(0, (int) ($session->cooldown_seconds ?? 300));
                    $adaptiveFactor = 2 ** min($consecutiveLosses, 4); // max 16x
                    $adaptiveCooldown = $baseCooldown * $adaptiveFactor;
                    if ($adaptiveCooldown > 0) {
                        $extraRuntime['cooldown_until'] = now()->addSeconds($adaptiveCooldown)->toIso8601String();
                        $session->last_error = sprintf(
                            'Loss cooldown (%dx): resuming in %ds.',
                            $adaptiveFactor,
                            $adaptiveCooldown
                        );
                    }
                }

                // Accumulate daily loss total.
                $todayCheck = now()->utc()->toDateString();
                $storedDate = (string) ($rt2['daily_loss_date'] ?? '');
                $runningTotal = ($storedDate === $todayCheck) ? (float) ($rt2['daily_loss_total'] ?? 0) : 0.0;
                $runningTotal += $absLoss;
                $extraRuntime['daily_loss_date'] = $todayCheck;
                $extraRuntime['daily_loss_total'] = round($runningTotal, 8);

                // Check if daily limit is now exceeded.
                $dailyLimitCfg = $session->daily_loss_limit !== null ? (float) $session->daily_loss_limit : null;
                if ($dailyLimitCfg !== null && $runningTotal >= $dailyLimitCfg) {
                    $extraRuntime['daily_loss_hit'] = true;
                    $extraRuntime['cooldown_until'] = null; // daily limit overrides cooldown
                    $session->last_error = sprintf(
                        'Daily loss limit $%.2f reached ($%.2f lost today). Trading resumes tomorrow (UTC midnight).',
                        $dailyLimitCfg,
                        $runningTotal
                    );
                }
            } else {
                // Win or break-even: clear cooldown and reset signal streak.
                $extraRuntime['cooldown_until'] = null;
                $session->signal_streak = 0;
                $session->last_signal_direction = null;
            }

            if ($extraRuntime !== []) {
                $session->runtime_json = array_merge($rt2, $extraRuntime);
                $session->save();
            }
            // ────────────────────────────────────────────────────────────────

            return [
                'session_id' => $session->id,
                'status' => 'closed',
                'action' => 'trade_closed',
                'trade_pnl' => round($tradePnl, 8),
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
            'last_signal_analysis' => ((array) $session->runtime_json)['latest_signal_analysis'] ?? null,
            'cooldown' => $this->cooldownStatus($session),
            'daily_loss' => $this->dailyLossStatus($session),
            'volatility' => ((array) $session->runtime_json)['volatility'] ?? null,
            'signal_streak' => (int) $session->signal_streak,
            'as_of' => now()->toIso8601String(),
        ];
    }

    // ─── Volatility helpers ───────────────────────────────────────────────────

    /**
     * ATR + Bollinger Band width check. Returns whether the market has enough
     * directional energy to trade. Thresholds scale with timeframe.
     *
     * @param  array<array{open:mixed,high:mixed,low:mixed,close:mixed}>  $candles
     * @return array{tradeable: bool, reason: string, atr_pct: float, bb_width_pct: float}
     */
    private function computeVolatilityGate(array $candles, string $timeframe): array
    {
        if (count($candles) < 20) {
            return ['tradeable' => true, 'reason' => 'insufficient_data', 'atr_pct' => 0.0, 'bb_width_pct' => 0.0];
        }

        $highs  = array_column($candles, 'high');
        $lows   = array_column($candles, 'low');
        $closes = array_column($candles, 'close');
        $last   = (float) end($closes);

        // ATR (14 periods, True Range)
        $trValues = [];
        for ($i = 1; $i < count($candles) && $i <= 14; $i++) {
            $trValues[] = max(
                (float) $highs[$i] - (float) $lows[$i],
                abs((float) $highs[$i] - (float) $closes[$i - 1]),
                abs((float) $lows[$i]  - (float) $closes[$i - 1])
            );
        }
        $atr    = count($trValues) > 0 ? array_sum($trValues) / count($trValues) : 0.0;
        $atrPct = $last > 0 ? ($atr / $last) * 100 : 0.0;

        // Bollinger Band width (20 periods, 2σ)
        $slice  = array_slice($closes, -20);
        $mean   = array_sum($slice) / count($slice);
        $stddev = sqrt(array_sum(array_map(fn ($c) => ((float) $c - $mean) ** 2, $slice)) / count($slice));
        $bbWidthPct = $mean > 0 ? (4 * $stddev / $mean) * 100 : 0.0; // (upper - lower) / mean

        // Minimum ATR threshold scales with timeframe
        $tfStr      = strtolower((string) $timeframe);
        $tfMinutes  = preg_match('/^(\d+)m$/i', $tfStr, $m) ? (int) $m[1] : (preg_match('/^(\d+)h$/i', $tfStr, $m) ? (int) $m[1] * 60 : 1);
        $minAtrPct  = match(true) {
            $tfMinutes <= 1  => 0.07,
            $tfMinutes <= 5  => 0.10,
            $tfMinutes <= 15 => 0.13,
            $tfMinutes <= 60 => 0.20,
            default          => 0.25,
        };
        $minBbWidthPct = $minAtrPct * 2.5;

        if ($atrPct < $minAtrPct) {
            return [
                'tradeable'    => false,
                'reason'       => sprintf('ATR too low: %.4f%% < %.4f%% min', $atrPct, $minAtrPct),
                'atr_pct'      => round($atrPct, 4),
                'bb_width_pct' => round($bbWidthPct, 4),
            ];
        }
        if ($bbWidthPct < $minBbWidthPct) {
            return [
                'tradeable'    => false,
                'reason'       => sprintf('BB squeeze: %.4f%% < %.4f%% min', $bbWidthPct, $minBbWidthPct),
                'atr_pct'      => round($atrPct, 4),
                'bb_width_pct' => round($bbWidthPct, 4),
            ];
        }

        return ['tradeable' => true, 'reason' => 'ok', 'atr_pct' => round($atrPct, 4), 'bb_width_pct' => round($bbWidthPct, 4)];
    }

    /**
     * Average ATR as % of price over the last 20 periods. Used for position sizing.
     *
     * @param  array<array{high:mixed,low:mixed,close:mixed}>  $candles
     */
    private function computeAtrAvgPct(array $candles): float
    {
        $highs  = array_column($candles, 'high');
        $lows   = array_column($candles, 'low');
        $closes = array_column($candles, 'close');
        $trs    = [];
        $count  = min(count($candles), 21);
        for ($i = 1; $i < $count; $i++) {
            $trs[] = max(
                (float) $highs[$i] - (float) $lows[$i],
                abs((float) $highs[$i] - (float) $closes[$i - 1]),
                abs((float) $lows[$i]  - (float) $closes[$i - 1])
            );
        }
        $last = (float) end($closes);
        return (count($trs) > 0 && $last > 0) ? (array_sum($trs) / count($trs) / $last) * 100 : 0.0;
    }

    // ─── Cooldown & daily-loss helpers ───────────────────────────────────────

    /**
     * @return array{active: bool, seconds_left: int, until: string|null}
     */
    private function cooldownStatus(RobotSession $session): array
    {
        $rt = (array) $session->runtime_json;
        $until = $rt['cooldown_until'] ?? null;
        if ($until && now()->lt(\Illuminate\Support\Carbon::parse($until))) {
            return [
                'active' => true,
                'seconds_left' => (int) now()->diffInSeconds(\Illuminate\Support\Carbon::parse($until)),
                'until' => $until,
            ];
        }
        return ['active' => false, 'seconds_left' => 0, 'until' => null];
    }

    /**
     * @return array{today: float, limit: float|null, hit: bool, pct: float|null}
     */
    private function dailyLossStatus(RobotSession $session): array
    {
        $rt = (array) $session->runtime_json;
        $todayUtc = now()->utc()->toDateString();
        $storedDate = (string) ($rt['daily_loss_date'] ?? '');
        $total = ($storedDate === $todayUtc) ? (float) ($rt['daily_loss_total'] ?? 0) : 0.0;
        $limit = $session->daily_loss_limit !== null ? (float) $session->daily_loss_limit : null;
        $hit = ($storedDate === $todayUtc) && (bool) ($rt['daily_loss_hit'] ?? false);
        return [
            'today' => round($total, 2),
            'limit' => $limit !== null ? round($limit, 2) : null,
            'hit' => $hit,
            'pct' => ($limit !== null && $limit > 0) ? round($total / $limit * 100, 1) : null,
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
