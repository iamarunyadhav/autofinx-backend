<?php

namespace App\Console\Commands;

use App\Domain\RatioBot\ForecastHorizonBacktestService;
use App\Models\RobotSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Periodic calibration refresh for Robot Trade (Unified Forecast Engine
 * plan -- Robot Trade/Future Trade/Fast2X wiring task). Mirrors
 * QuickTradeForecastBacktestCommand's own "periodic job writes, live
 * evaluation path only reads (or, here, doesn't even read yet)" split:
 * NEVER called from RobotSessionService::evaluateSession() or any other
 * live entry/exit decision path, so it can never affect a real order.
 *
 * Outcome contract for Robot Trade is IDENTICAL to Quick Trade's, not a new
 * one invented here: every Robot Trade entry (both timed_expiry and
 * candle_direction bot modes -- see RobotSessionService::evaluateSession())
 * places its position via QuickTradeService::place() with the session's own
 * expiry_seconds, so "did price move in the predicted direction by the time
 * expiry_seconds worth of CLOSED candles have elapsed" is the exact same
 * candle-based judgment Quick Trade's own contract already uses -- this is
 * not a guess, it is what the session's real expiry_seconds field already
 * measures for the trade QuickTradeService itself settles.
 *
 * bot_instance_id = RobotSession::id (the real, non-null UUID primary key of
 * the specific bot instance being calibrated) -- NOT the
 * "{exchange}:{symbol}:{timeframe}" convention Quick Trade uses, because
 * unlike Quick Trade (no persistent per-bot config row), a RobotSession IS a
 * persistent, single-symbol bot config row exactly like RatioBotConfig, and
 * ForecastModelPromotionGate needs to gate per SPECIFIC bot instance (e.g.
 * two sessions trading the same symbol with different confidence
 * thresholds/indicator sets must never be blended into one calibration row).
 */
class RobotTradeForecastBacktestCommand extends Command
{
    protected $signature = 'robot-trade:forecast-backtest
        {--session= : Backtest one robot session id}
        {--limit=50 : Maximum robot sessions to backtest}';

    protected $description = 'Walk-forward calibration refresh of the Unified Forecast Engine for active Robot Trade sessions (tracking-only -- never affects any live session decision).';

    public function handle(ForecastHorizonBacktestService $service): int
    {
        $sessionId = trim((string) $this->option('session'));
        $limit = max(1, min(200, (int) $this->option('limit')));

        $sessions = RobotSession::query()
            ->when($sessionId === '', fn ($query) => $query->whereIn('status', ['RUNNING', 'PAUSED']))
            ->when($sessionId !== '', fn ($query) => $query->whereKey($sessionId))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($sessions as $session) {
            $symbol = (string) $session->symbol;
            $timeframe = (string) $session->timeframe;
            $horizonSeconds = (int) $session->expiry_seconds;
            $exchange = (string) ($session->provider_key ?: 'binance');

            if ($symbol === '' || $timeframe === '' || $horizonSeconds <= 0) {
                continue;
            }

            try {
                $service->runForBot('robot_trade', (string) $session->id, $exchange, $symbol, $timeframe, $horizonSeconds);
                $processed++;
            } catch (Throwable $e) {
                Log::warning('[RobotTradeForecastBacktest] Failed for session', [
                    'session_id' => $session->id,
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'horizon_seconds' => $horizonSeconds,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Robot Trade forecast calibration refreshed for {$processed}/{$sessions->count()} sessions.");

        return self::SUCCESS;
    }
}
