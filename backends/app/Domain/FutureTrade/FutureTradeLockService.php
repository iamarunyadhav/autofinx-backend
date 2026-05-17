<?php

namespace App\Domain\FutureTrade;

use App\Models\FutureTradeBotConfig;
use App\Models\FutureTradeLogicalTrade;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single source of truth for entry/exit locking + DB-level duplicate checks.
 *
 * Lock model:
 *   - Per-symbol entry lock (autofinx_ft:entry_lock:{config}:{symbol})
 *   - Per-symbol exit lock  (autofinx_ft:exit_lock:{config}:{symbol})
 *   - Global executor lock  (autofinx_ft:executor_lock)
 *
 * The caller must invoke withEntryLock() or withExitLock(); the closure receives
 * a re-check helper that re-reads the open-position state inside the lock so the
 * race between read and write is closed.
 */
class FutureTradeLockService
{
    public const DEFAULT_ENTRY_LOCK_SECONDS = 30;
    public const DEFAULT_EXIT_LOCK_SECONDS = 30;
    public const DEFAULT_EXECUTOR_LOCK_SECONDS = 600;

    public function entryLockKey(FutureTradeBotConfig $config, string $symbol): string
    {
        return sprintf('autofinx_ft:entry_lock:%s:%s', $config->id, strtoupper($symbol));
    }

    public function exitLockKey(FutureTradeBotConfig $config, string $symbol): string
    {
        return sprintf('autofinx_ft:exit_lock:%s:%s', $config->id, strtoupper($symbol));
    }

    public function executorLockKey(): string
    {
        return 'autofinx_ft:executor_lock';
    }

    /**
     * Run the closure with the entry lock held. The closure must return a value
     * (which is propagated back) or throw. Returns null if the lock could not
     * be acquired (the caller should treat this as a hard "BLOCKED — concurrent
     * entry attempt" event).
     *
     * @template T
     * @param callable(): T $callback
     * @return T|null
     */
    public function withEntryLock(FutureTradeBotConfig $config, string $symbol, callable $callback, int $seconds = self::DEFAULT_ENTRY_LOCK_SECONDS)
    {
        return $this->withLock($this->entryLockKey($config, $symbol), $seconds, $callback);
    }

    public function withExitLock(FutureTradeBotConfig $config, string $symbol, callable $callback, int $seconds = self::DEFAULT_EXIT_LOCK_SECONDS)
    {
        return $this->withLock($this->exitLockKey($config, $symbol), $seconds, $callback);
    }

    public function withExecutorLock(callable $callback, int $seconds = self::DEFAULT_EXECUTOR_LOCK_SECONDS)
    {
        return $this->withLock($this->executorLockKey(), $seconds, $callback);
    }

    private function withLock(string $key, int $seconds, callable $callback)
    {
        $lock = Cache::lock($key, max(5, $seconds));
        if (! $lock->get()) {
            Log::info('[FT LOCK SKIP]', ['key' => $key]);
            return null;
        }

        try {
            return $callback();
        } catch (Throwable $e) {
            Log::warning('[FT LOCK CALLBACK ERROR]', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $ignored) {
                // best effort
            }
        }
    }

    /**
     * Re-check inside the lock: does this config/symbol already have an active
     * logical trade? Returns true when the caller MUST abort.
     */
    public function hasActiveLogicalTrade(string $configId, string $symbol): bool
    {
        return FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $configId)
            ->where('symbol', strtoupper($symbol))
            ->whereIn('status', FutureTradeLogicalTrade::ACTIVE_STATUSES)
            ->exists();
    }

    /**
     * Re-check inside the lock: does this config/symbol/signal_key already exist?
     * This blocks the "same signal arrives twice within ~2 seconds" duplicate.
     */
    public function hasSignalKey(string $configId, string $signalKey): bool
    {
        if (trim($signalKey) === '') {
            return false;
        }
        return FutureTradeLogicalTrade::query()
            ->where('future_trade_bot_config_id', $configId)
            ->where('signal_key', $signalKey)
            ->exists();
    }
}
