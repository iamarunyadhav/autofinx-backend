<?php

namespace App\Jobs;

use App\Domain\AuditResearch\AuditEventRecorder;
use App\Domain\Market\MarketPriceCacheService;
use App\Domain\Trading\LiveTimedTradeService;
use App\Domain\Trading\QuickTradeService;
use App\Models\AuditEvent;
use App\Models\QuickTrade;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Per-tick check of open, live Quick Trades that have an optional per-trade
 * stop-loss (`stop_loss_pct`) and/or take-profit (`take_profit_pct`)
 * configured. Dispatched synchronously (never queued for real) by
 * MonitorQuickTradeStopLossCommand every few seconds. Kept as one job/class
 * (not split/renamed) since both conditions reuse the exact same per-tick
 * price read, per-position lock, and settlement call — a second parallel
 * poller would just duplicate that work against the same price cache.
 *
 * Both conditions are checked in the same tick, on the same fresh price
 * snapshot: whichever threshold is met first triggers the early close, and
 * the other is simply never reached for that trade (the trade is already
 * settled) — they cannot both fire for the same trade.
 *
 * This does NOT introduce a new way to close a live futures position — it
 * calls the same `QuickTradeService::settleTrade()` used by the normal
 * expiry-settlement path. `settleTrade()` only gates on `status === 'open'`
 * (not on `expires_at`), so calling it before natural expiry is already a
 * correct, fee-reconciling early-close settlement.
 */
class CheckQuickTradeStopLossJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('monitoring_safe');
    }

    public function handle(
        MarketPriceCacheService $prices,
        LiveTimedTradeService $liveTimedTrades,
        QuickTradeService $quickTrades,
    ): void {
        $trades = QuickTrade::query()
            ->where('status', 'open')
            ->where('mode', 'live')
            ->where(function ($query): void {
                $query->whereNotNull('stop_loss_pct')
                    ->orWhereNotNull('take_profit_pct');
            })
            ->where('expires_at', '>', now())
            ->get();

        foreach ($trades as $trade) {
            try {
                $this->checkTrade($trade, $prices, $liveTimedTrades, $quickTrades);
            } catch (\Throwable $e) {
                // One bad trade must never stop the tick from checking the
                // rest, and must never bubble back into the command's loop.
                Log::warning('quick_trade_stop_loss_check_failed', [
                    'quick_trade_id' => $trade->id,
                    'symbol' => $trade->symbol,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function checkTrade(
        QuickTrade $trade,
        MarketPriceCacheService $prices,
        LiveTimedTradeService $liveTimedTrades,
        QuickTradeService $quickTrades,
    ): void {
        $lock = Cache::lock('quick-trade-stop-loss:'.$trade->id, 15);
        if (! $lock->get()) {
            // Another tick/worker is already mid-check for this position.
            return;
        }

        try {
            // Re-fetch under the lock in case another process settled it
            // (expiry, manual close, another tick) between the query above
            // and acquiring this lock.
            $fresh = QuickTrade::find($trade->id);
            if (! $fresh || $fresh->status !== 'open') {
                return;
            }
            if ($fresh->stop_loss_pct === null && $fresh->take_profit_pct === null) {
                return;
            }

            $snapshot = $prices->getLiveFeedSnapshot('binance_futures', $fresh->symbol, 15);

            // Never act on a missing or stale snapshot — skip this trade this
            // tick and log a warning rather than guessing at a price.
            if (! is_array($snapshot) || ($snapshot['fresh'] ?? false) !== true || (float) ($snapshot['price'] ?? 0) <= 0) {
                Log::warning('quick_trade_stop_loss_stale_price_skipped', [
                    'quick_trade_id' => $fresh->id,
                    'symbol' => $fresh->symbol,
                    'age_seconds' => $snapshot['age_seconds'] ?? null,
                ]);

                return;
            }

            $currentPrice = (float) $snapshot['price'];
            $stake = (float) $fresh->stake_amount;
            if ($stake <= 0) {
                return;
            }

            // Reuse the existing raw price-based P&L helper (the same one
            // `settleLiveTrade()` uses for the gross P&L before fees) rather
            // than duplicating the delta/side math here. Both stop-loss and
            // take-profit are checked against this SAME snapshot/pnlPct — one
            // tick, one price read, whichever threshold is met first wins.
            $rawPnl = $liveTimedTrades->pnlFor($fresh, $currentPrice);
            $pnlPct = ($rawPnl / $stake) * 100;

            $stopLossThreshold = $fresh->stop_loss_pct !== null ? (float) $fresh->stop_loss_pct : null;
            $takeProfitThreshold = $fresh->take_profit_pct !== null ? (float) $fresh->take_profit_pct : null;

            $stopLossHit = $stopLossThreshold !== null && $pnlPct <= $stopLossThreshold;
            $takeProfitHit = ! $stopLossHit && $takeProfitThreshold !== null && $pnlPct >= $takeProfitThreshold;

            if (! $stopLossHit && ! $takeProfitHit) {
                // Neither threshold reached yet this tick.
                return;
            }

            $settled = $quickTrades->settleTrade($fresh);

            if ($stopLossHit) {
                $settled->update(['stop_loss_triggered_at' => now()]);

                AuditEventRecorder::record(
                    eventType: 'quick_trade_stop_loss_triggered',
                    category: AuditEvent::CATEGORY_RISK,
                    context: [
                        'bot_type' => 'quick_trade',
                        'bot_instance_id' => $settled->robot_session_id,
                        'environment' => 'live',
                        'symbol' => $settled->symbol,
                        'market_price' => $currentPrice,
                        'metadata' => [
                            'quick_trade_id' => $settled->id,
                            'trigger_price' => $currentPrice,
                            'loss_pct' => round($pnlPct, 4),
                            'stop_loss_pct' => $stopLossThreshold,
                        ],
                    ],
                    correlationId: $settled->id,
                    severity: AuditEvent::SEVERITY_HIGH,
                );
            } else {
                $settled->update(['take_profit_triggered_at' => now()]);

                AuditEventRecorder::record(
                    eventType: 'quick_trade_take_profit_triggered',
                    category: AuditEvent::CATEGORY_RISK,
                    context: [
                        'bot_type' => 'quick_trade',
                        'bot_instance_id' => $settled->robot_session_id,
                        'environment' => 'live',
                        'symbol' => $settled->symbol,
                        'market_price' => $currentPrice,
                        'metadata' => [
                            'quick_trade_id' => $settled->id,
                            'trigger_price' => $currentPrice,
                            'profit_pct' => round($pnlPct, 4),
                            'take_profit_pct' => $takeProfitThreshold,
                        ],
                    ],
                    correlationId: $settled->id,
                    severity: AuditEvent::SEVERITY_MEDIUM,
                );
            }
        } finally {
            $lock->release();
        }
    }
}
