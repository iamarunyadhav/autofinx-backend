<?php

namespace App\Domain\Risk;

use App\Domain\Trading\BotLogService;
use App\Models\Bot;
use App\Models\MarketOhlc;
use App\Models\Position;
use App\Models\Trade;
use App\Models\WalletAccount;
use RuntimeException;

class BotRiskEngine
{
    public function __construct(private readonly BotLogService $logs)
    {
    }

    public function enforceBeforeExecution(Bot $bot, string $side, float $price, float $qty, string $traceId): void
    {
        $profile = $bot->riskProfile;
        if (! $profile) {
            return;
        }

        if ($bot->cooldown_until && now()->lt($bot->cooldown_until)) {
            throw new RuntimeException('Bot is in cooldown window after an enforced stop.');
        }

        $maxOpen = (int) $this->rule($profile, 'max_open_positions', 0);
        if ($maxOpen > 0 && strtolower($side) === 'buy') {
            $openCount = Position::where('workspace_id', $bot->workspace_id)
                ->where('bot_id', $bot->id)
                ->whereRaw('COALESCE(qty, quantity, 0) > 0')
                ->count();
            if ($openCount >= $maxOpen) {
                throw new RuntimeException('Max open positions reached for this bot.');
            }
        }

        $this->enforceMetalsSessionAndTradeRate($bot, $traceId);
        $this->enforceVolatilityCircuitBreaker($bot, $traceId);
        $this->enforceDailyLoss($bot, $traceId);
        $this->enforceDrawdown($bot, $traceId);
    }

    public function enforceAfterReconcile(Bot $bot, string $traceId): void
    {
        if (! $bot->riskProfile) {
            return;
        }

        $this->enforceDailyLoss($bot, $traceId);
        $this->enforceDrawdown($bot, $traceId);
    }

    private function enforceDailyLoss(Bot $bot, string $traceId): void
    {
        $limit = $this->resolveDailyLossLimit($bot);
        if ($limit <= 0) {
            return;
        }

        $todayLoss = (float) Trade::where('bot_id', $bot->id)
            ->whereDate('ts', now()->toDateString())
            ->where('realized_pnl', '<', 0)
            ->sum('realized_pnl');

        if (abs($todayLoss) > $limit) {
            $this->forceStop($bot, 'Daily loss limit reached', $traceId, [
                'today_loss' => $todayLoss,
                'limit' => $limit,
            ]);
            throw new RuntimeException('Risk stop: max daily loss reached.');
        }
    }

    private function enforceDrawdown(Bot $bot, string $traceId): void
    {
        $profile = $bot->riskProfile;
        $maxDrawdownPct = (float) ($this->rule($profile, 'max_drawdown_pct', 0) ?: $this->rule($profile, 'drawdown_pct', 0));
        if ($maxDrawdownPct <= 0) {
            return;
        }

        $equity = (float) WalletAccount::where('workspace_id', $bot->workspace_id)->sum('balance');
        $params = $bot->strategy_params ?? [];
        $baseline = (float) ($params['risk_baseline_equity'] ?? 0);
        if ($baseline <= 0) {
            $params['risk_baseline_equity'] = max($equity, 0);
            $bot->strategy_params = $params;
            $bot->save();
            return;
        }
        if ($baseline <= 0) {
            return;
        }

        $drawdown = (($baseline - $equity) / $baseline) * 100;
        if ($drawdown >= $maxDrawdownPct) {
            $this->forceStop($bot, 'Drawdown threshold reached', $traceId, [
                'drawdown_pct' => $drawdown,
                'threshold_pct' => $maxDrawdownPct,
                'baseline_equity' => $baseline,
                'current_equity' => $equity,
            ]);
            throw new RuntimeException('Risk stop: max drawdown reached.');
        }
    }

    private function enforceVolatilityCircuitBreaker(Bot $bot, string $traceId): void
    {
        $profile = $bot->riskProfile;
        $threshold = (float) $this->rule($profile, 'circuit_breaker_vol_threshold', 0);
        if ($threshold <= 0) {
            return;
        }

        $candles = MarketOhlc::where('exchange', strtolower($bot->exchange))
            ->where('symbol', strtoupper($bot->symbol))
            ->where('timeframe', '1m')
            ->orderByDesc('ts')
            ->limit(40)
            ->get()
            ->reverse()
            ->values();

        if ($candles->count() < 10) {
            return;
        }

        $returns = [];
        $prev = null;
        foreach ($candles as $candle) {
            $close = (float) $candle->close;
            if ($prev !== null && $prev > 0) {
                $returns[] = (($close - $prev) / $prev) * 100;
            }
            $prev = $close;
        }

        if (count($returns) < 5) {
            return;
        }

        $mean = array_sum($returns) / count($returns);
        $variance = 0.0;
        foreach ($returns as $ret) {
            $variance += ($ret - $mean) ** 2;
        }
        $stdDev = sqrt($variance / max(1, count($returns) - 1));

        if ($stdDev > $threshold) {
            $cooldown = (int) $this->rule($profile, 'cooldown_sec_after_stop', 0);
            $bot->status = 'PAUSED';
            $bot->cooldown_until = $cooldown > 0 ? now()->addSeconds($cooldown) : null;
            $bot->save();

            $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Volatility circuit breaker triggered; bot paused.', [
                'std_dev' => $stdDev,
                'threshold' => $threshold,
                'cooldown_sec' => $cooldown,
            ], $traceId);

            throw new RuntimeException('Risk pause: volatility spike exceeded threshold.');
        }
    }

    private function enforceMetalsSessionAndTradeRate(Bot $bot, string $traceId): void
    {
        if (! $bot->isMetals()) {
            return;
        }

        $params = $bot->strategy_params ?? [];
        $enforceHours = (bool) ($params['enforce_market_hours'] ?? true);
        if ($enforceHours) {
            $hours = config('assets.metals.market_hours', []);
            $timezone = (string) ($hours['timezone'] ?? 'UTC');
            $open = (string) ($hours['open'] ?? '00:00');
            $close = (string) ($hours['close'] ?? '23:59');
            $closedWeekdays = (array) ($hours['closed_weekdays'] ?? []);

            $now = now()->setTimezone($timezone);
            if (in_array($now->dayOfWeek, $closedWeekdays, true) || ! $this->isWithinSession($now->format('H:i'), $open, $close)) {
                $this->logs->log($bot->id, $bot->workspace_id, 'WARN', 'Metals market session closed; execution blocked.', [
                    'timezone' => $timezone,
                    'open' => $open,
                    'close' => $close,
                ], $traceId);
                throw new RuntimeException('Metals market session is closed.');
            }
        }

        $maxTradesPerHour = max(0, (int) ($params['max_trades_per_hour'] ?? 0));
        if ($maxTradesPerHour > 0) {
            $tradesLastHour = Trade::where('bot_id', $bot->id)
                ->where('ts', '>=', now()->subHour())
                ->count();
            if ($tradesLastHour >= $maxTradesPerHour) {
                throw new RuntimeException('Max trades per hour reached for this metals bot.');
            }
        }
    }

    private function forceStop(Bot $bot, string $message, string $traceId, array $meta = []): void
    {
        $cooldownSec = (int) $this->rule($bot->riskProfile, 'cooldown_sec_after_stop', 0);
        $bot->status = 'STOPPED';
        $bot->cooldown_until = $cooldownSec > 0 ? now()->addSeconds($cooldownSec) : null;
        $bot->save();

        $this->logs->log($bot->id, $bot->workspace_id, 'ERROR', $message, array_merge($meta, [
            'cooldown_sec_after_stop' => $cooldownSec,
        ]), $traceId);
    }

    private function rule($profile, string $key, $default)
    {
        if (! $profile) {
            return $default;
        }

        if ($profile->{$key} !== null) {
            return $profile->{$key};
        }

        return $profile->rules[$key] ?? $default;
    }

    private function resolveDailyLossLimit(Bot $bot): float
    {
        $profile = $bot->riskProfile;
        $explicitAmount = (float) $this->rule($profile, 'max_daily_loss', 0);
        if ($explicitAmount > 0) {
            return $explicitAmount;
        }

        $percent = (float) ($this->rule($profile, 'daily_loss_pct', 0) ?: $this->rule($profile, 'max_daily_loss_pct', 0));
        if ($percent <= 0) {
            return 0;
        }

        $equity = (float) WalletAccount::where('workspace_id', $bot->workspace_id)->sum('balance');
        if ($equity <= 0) {
            return 0;
        }

        return ($equity * $percent) / 100;
    }

    private function isWithinSession(string $now, string $open, string $close): bool
    {
        if ($open === $close) {
            return true;
        }

        // Handles both normal sessions (09:00-17:00) and overnight sessions (22:00-05:00).
        if ($open < $close) {
            return $now >= $open && $now <= $close;
        }

        return $now >= $open || $now <= $close;
    }
}
