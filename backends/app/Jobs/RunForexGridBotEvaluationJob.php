<?php

namespace App\Jobs;

use App\Domain\ForexGrid\GridLotSizer;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Models\ExchangeConnection;
use App\Models\ForexGridBotConfig;
use App\Models\ForexGridBotEvent;
use App\Models\ForexGridBotLevel;
use App\Models\ForexGridBotState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled or manually triggered evaluation of one Forex Grid bot config.
 *
 * Runs entirely independently of the Ratio Bot engine -- separate queue
 * (`forex_grid`, NOT `ratio_bot`), separate tables, separate venue client
 * resolution path (App\Domain\Venue\TradingVenueClientFactory::forProvider()).
 *
 * ---------------------------------------------------------------------
 * GRID ANCHORING & LEVEL-OPENING CONVENTION (the spec left the exact rule
 * to the implementer -- this is the deterministic, testable convention this
 * job implements; nothing here is implicit):
 * ---------------------------------------------------------------------
 *
 * 1. ANCHOR: The first time a config's state transitions from `idle` to
 *    `running` (the first evaluation tick after the bot is started), this
 *    job takes one getLatestPrice() snapshot and immediately opens Level 0
 *    at that price, unconditionally (still subject to the same grid-cap /
 *    exposure-cap gates as every other level -- trivially satisfied since
 *    0 levels are open yet). Level 0's side is `buy` for direction_mode
 *    `both` or `buy_only`, `sell` for `sell_only`. This price is the grid's
 *    anchor -- it is never stored as a separate column, it is simply
 *    Level 0's entry_price.
 *
 * 2. SUBSEQUENT LEVELS: Buy-side and sell-side levels are counted and
 *    triggered INDEPENDENTLY of each other (this matters for
 *    GridLotSizer::lotSizeForLevel(), which escalates per side):
 *      - A new BUY level (only considered when direction_mode is `both` or
 *        `buy_only`) opens when the current mid price has fallen to (or
 *        below) grid_spacing_pips below the entry_price of the
 *        highest-level_index currently-open (status=filled) BUY level.
 *      - A new SELL level (only considered when direction_mode is `both`
 *        or `sell_only`) opens when the current mid price has risen to
 *        (or above) grid_spacing_pips above the entry_price of the
 *        highest-level_index currently-open SELL level.
 *      - At most ONE new level opens per evaluation tick (per job
 *        execution) -- if both a buy and a sell signal are true on the
 *        same tick, the buy signal is processed first and the sell signal
 *        is picked up on the next tick. This keeps each tick's outcome
 *        deterministic and independently testable.
 *
 * 3. PIP SIZE: 1 pip = 0.01 for any symbol ending in `JPY`, else 0.0001
 *    for every other forex/metals symbol this bot trades (including
 *    XAUUSD/XAGUSD, treated the same as any other 4-decimal pair for this
 *    module's purposes -- a deliberate simplification, not a claim about
 *    real broker pip conventions for metals).
 *
 * 4. CAPS (checked BEFORE ever calling placeOrder()):
 *      - max_grid_levels: blocks when the TOTAL currently-open level count
 *        (both sides combined) is already >= max_grid_levels.
 *      - max_total_exposure_lots: blocks when (sum of lot_size across all
 *        currently-open levels) + (the candidate new level's
 *        GridLotSizer-computed lot size) would exceed the cap.
 *    Either violation creates a ForexGridBotEvent (event_type='blocked',
 *    context_json.reason='grid_cap_exceeded', .cap=<which cap>) and skips
 *    the level -- placeOrder() is never called.
 *
 * 5. BASKET STOP-LOSS / TAKE-PROFIT: after any level-open logic for this
 *    tick, the basket's floating P&L % is computed across every
 *    currently-open (status=filled) level:
 *        weighted_pnl = sum over open levels of:
 *            side=buy:  (mid - entry_price) * lot_size
 *            side=sell: (entry_price - mid) * lot_size
 *        notional = sum over open levels of (entry_price * lot_size)
 *        basket_pnl_pct = (weighted_pnl / notional) * 100
 *    If basket_pnl_pct <= -basket_stop_loss_pct, every open level is
 *    closed (opposite-side placeOrder() for filled levels, cancelOrder()
 *    for any still-pending ones), state.status becomes `stopped` (manual
 *    re-enable required via the controller's /start route), and a
 *    basket_closed event is recorded with reason=stop_loss_triggered.
 *    basket_take_profit_pct is the symmetric mirror
 *    (reason=take_profit_triggered).
 *
 * 6. FEED STALENESS: OANDA has no websocket in this module (REST-polled
 *    only), so there is no websocket-vs-scheduled-job race the way
 *    DetectStaleFeedsJob/OperationalBreakerService guard against for
 *    Ratio Bot -- the SAME job run that would detect staleness is the one
 *    evaluating right now. A tick is only ever "stale" if getLatestPrice()
 *    itself throws (caught below, logged as a feed_error event, state left
 *    untouched so the next scheduled tick retries) -- see
 *    App\Domain\ForexGrid\ForexGridFeedFreshnessService for the
 *    wall-clock-age half of that check, used defensively by callers that
 *    want to flag a bot whose last successful tick is unusually old.
 */
class RunForexGridBotEvaluationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;
    public int $uniqueFor = 5;

    public function __construct(
        public readonly string $forexGridBotConfigId,
        public readonly bool $manualTrigger = false,
    ) {
        $this->onQueue('forex_grid');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('forex-grid-eval:' . $this->forexGridBotConfigId))->expireAfter(10),
        ];
    }

    public function uniqueId(): string
    {
        return 'forex-grid-eval:' . $this->forexGridBotConfigId;
    }

    public function handle(TradingVenueClientFactory $clients, GridLotSizer $lotSizer): void
    {
        $config = ForexGridBotConfig::with('state')->find($this->forexGridBotConfigId);
        if (! $config) {
            Log::warning('[ForexGridJob] Config not found', ['id' => $this->forexGridBotConfigId]);
            return;
        }

        if (! $config->enabled && ! $this->manualTrigger) {
            return;
        }

        $lockKey = 'forex-grid-eval-bot:' . $config->id;
        $lock = Cache::lock($lockKey, 10);
        if (! $lock->get()) {
            Log::debug('[ForexGridJob] Duplicate evaluation blocked by per-bot lock', ['config_id' => $config->id]);
            return;
        }

        try {
            $state = ForexGridBotState::firstOrCreate(
                ['forex_grid_bot_config_id' => $config->id],
                ['status' => ForexGridBotState::STATUS_IDLE],
            );

            if (in_array($state->status, [ForexGridBotState::STATUS_STOPPED, ForexGridBotState::STATUS_BLOCKED, ForexGridBotState::STATUS_PAUSED], true)) {
                return;
            }

            $connection = $config->connection_id ? ExchangeConnection::find($config->connection_id) : null;

            try {
                $venue = $clients->forProvider($config->providerKey(), $connection);
                $quote = $venue->getLatestPrice($config->normalizedSymbol());
            } catch (Throwable $e) {
                $this->recordEvent($config, ForexGridBotEvent::EVENT_FEED_ERROR, ['message' => $e->getMessage()]);
                Log::warning('[ForexGridJob] getLatestPrice failed', ['config_id' => $config->id, 'error' => $e->getMessage()]);
                return;
            }

            $mid = (float) ($quote['mid'] ?? 0);
            if ($mid <= 0) {
                $this->recordEvent($config, ForexGridBotEvent::EVENT_FEED_ERROR, ['message' => 'non-positive mid price']);
                return;
            }

            if ($state->isIdle()) {
                $this->openAnchorLevel($config, $state, $mid);
            }

            $this->maybeOpenNextLevel($config, $state, $mid, $lotSizer, $venue);

            $this->maybeCloseBasket($config, $state->fresh(), $mid, $venue);

            $state->fresh()->update(['last_evaluated_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    private function openAnchorLevel(ForexGridBotConfig $config, ForexGridBotState $state, float $mid): void
    {
        $side = $config->direction_mode === 'sell_only' ? ForexGridBotLevel::SIDE_SELL : ForexGridBotLevel::SIDE_BUY;

        ForexGridBotLevel::create([
            'forex_grid_bot_config_id' => $config->id,
            'level_index' => 0,
            'side' => $side,
            'entry_price' => $mid,
            'lot_size' => (float) $config->base_lot_size,
            'order_id' => 'anchor-' . (string) $config->id,
            'status' => ForexGridBotLevel::STATUS_FILLED,
            'opened_at' => now(),
        ]);

        $state->update([
            'status' => ForexGridBotState::STATUS_RUNNING,
            'active_levels_count' => 1,
            'total_exposure_lots' => (float) $config->base_lot_size,
            'basket_avg_price' => $mid,
        ]);

        $this->recordEvent($config, ForexGridBotEvent::EVENT_STARTED, ['anchor_price' => $mid, 'side' => $side]);
    }

    private function maybeOpenNextLevel(ForexGridBotConfig $config, ForexGridBotState $state, float $mid, GridLotSizer $lotSizer, $venue): void
    {
        $openLevels = ForexGridBotLevel::query()
            ->where('forex_grid_bot_config_id', $config->id)
            ->where('status', ForexGridBotLevel::STATUS_FILLED)
            ->get();

        $totalActive = $openLevels->count();

        $buyLevels = $openLevels->where('side', ForexGridBotLevel::SIDE_BUY)->sortByDesc('level_index');
        $sellLevels = $openLevels->where('side', ForexGridBotLevel::SIDE_SELL)->sortByDesc('level_index');

        $buyCount = $buyLevels->count();
        $sellCount = $sellLevels->count();
        $lastBuy = $buyLevels->first();
        $lastSell = $sellLevels->first();

        $pip = $this->pipSize($config->normalizedSymbol());
        $gridDistance = (float) $config->grid_spacing_pips * $pip;

        $candidateSide = null;
        $candidateLevelIndex = null;

        if (in_array($config->direction_mode, ['both', 'buy_only'], true) && $lastBuy && $mid <= ((float) $lastBuy->entry_price - $gridDistance)) {
            $candidateSide = ForexGridBotLevel::SIDE_BUY;
            $candidateLevelIndex = $buyCount;
        } elseif (in_array($config->direction_mode, ['both', 'sell_only'], true) && $lastSell && $mid >= ((float) $lastSell->entry_price + $gridDistance)) {
            $candidateSide = ForexGridBotLevel::SIDE_SELL;
            $candidateLevelIndex = $sellCount;
        }

        if ($candidateSide === null) {
            return;
        }

        if ($totalActive >= (int) $config->max_grid_levels) {
            $this->recordEvent($config, ForexGridBotEvent::EVENT_BLOCKED, [
                'reason' => 'grid_cap_exceeded',
                'cap' => 'max_grid_levels',
            ]);
            return;
        }

        $lot = $lotSizer->lotSizeForLevel($candidateLevelIndex, $config);
        $currentExposure = (float) $openLevels->sum(fn ($level) => (float) $level->lot_size);
        $newExposure = $currentExposure + $lot;

        if ($newExposure > (float) $config->max_total_exposure_lots) {
            $this->recordEvent($config, ForexGridBotEvent::EVENT_BLOCKED, [
                'reason' => 'grid_cap_exceeded',
                'cap' => 'max_total_exposure_lots',
            ]);
            return;
        }

        $order = $venue->placeOrder([
            'asset_code' => $config->normalizedSymbol(),
            'side' => strtoupper($candidateSide),
            'qty' => $lot,
        ]);

        $entryPrice = (float) ($order['avg_price'] ?? $mid);

        ForexGridBotLevel::create([
            'forex_grid_bot_config_id' => $config->id,
            'level_index' => $candidateLevelIndex,
            'side' => $candidateSide,
            'entry_price' => $entryPrice,
            'lot_size' => $lot,
            'order_id' => (string) ($order['order_id'] ?? ''),
            'status' => ForexGridBotLevel::STATUS_FILLED,
            'opened_at' => now(),
        ]);

        $state->update([
            'active_levels_count' => $totalActive + 1,
            'total_exposure_lots' => $newExposure,
        ]);

        $this->recordEvent($config, ForexGridBotEvent::EVENT_LEVEL_OPENED, [
            'side' => $candidateSide,
            'level_index' => $candidateLevelIndex,
            'entry_price' => $entryPrice,
            'lot_size' => $lot,
        ]);
    }

    private function maybeCloseBasket(ForexGridBotConfig $config, ForexGridBotState $state, float $mid, $venue): void
    {
        $openLevels = ForexGridBotLevel::query()
            ->where('forex_grid_bot_config_id', $config->id)
            ->whereIn('status', [ForexGridBotLevel::STATUS_FILLED, ForexGridBotLevel::STATUS_PENDING])
            ->get();

        if ($openLevels->isEmpty()) {
            return;
        }

        $filled = $openLevels->where('status', ForexGridBotLevel::STATUS_FILLED);
        $notional = (float) $filled->sum(fn ($level) => (float) $level->entry_price * (float) $level->lot_size);

        if ($notional <= 0) {
            return;
        }

        $weightedPnl = (float) $filled->sum(function ($level) use ($mid) {
            $entry = (float) $level->entry_price;
            $lot = (float) $level->lot_size;

            return $level->side === ForexGridBotLevel::SIDE_BUY
                ? ($mid - $entry) * $lot
                : ($entry - $mid) * $lot;
        });

        $basketPnlPct = ($weightedPnl / $notional) * 100;

        $stopLossPct = $config->basket_stop_loss_pct !== null ? (float) $config->basket_stop_loss_pct : null;
        $takeProfitPct = $config->basket_take_profit_pct !== null ? (float) $config->basket_take_profit_pct : null;

        $reason = null;
        if ($stopLossPct !== null && $basketPnlPct <= -$stopLossPct) {
            $reason = 'stop_loss_triggered';
        } elseif ($takeProfitPct !== null && $basketPnlPct >= $takeProfitPct) {
            $reason = 'take_profit_triggered';
        }

        if ($reason === null) {
            return;
        }

        foreach ($openLevels as $level) {
            if ($level->status === ForexGridBotLevel::STATUS_PENDING) {
                if ($level->order_id) {
                    $venue->cancelOrder($level->order_id, $config->normalizedSymbol());
                }
                $level->update(['status' => ForexGridBotLevel::STATUS_CANCELLED, 'closed_at' => now()]);
                continue;
            }

            $closingSide = $level->side === ForexGridBotLevel::SIDE_BUY ? 'SELL' : 'BUY';
            $closeOrder = $venue->placeOrder([
                'asset_code' => $config->normalizedSymbol(),
                'side' => $closingSide,
                'qty' => (float) $level->lot_size,
            ]);

            $exitPrice = (float) ($closeOrder['avg_price'] ?? $mid);
            $entry = (float) $level->entry_price;
            $lot = (float) $level->lot_size;
            $realizedPnl = $level->side === ForexGridBotLevel::SIDE_BUY
                ? ($exitPrice - $entry) * $lot
                : ($entry - $exitPrice) * $lot;

            $level->update([
                'status' => ForexGridBotLevel::STATUS_CLOSED,
                'closed_at' => now(),
                'realized_pnl' => $realizedPnl,
            ]);
        }

        $state->update([
            'status' => ForexGridBotState::STATUS_STOPPED,
            'active_levels_count' => 0,
            'total_exposure_lots' => 0,
            'basket_avg_price' => null,
        ]);

        $this->recordEvent($config, ForexGridBotEvent::EVENT_BASKET_CLOSED, [
            'reason' => $reason,
            'basket_pnl_pct' => $basketPnlPct,
        ]);
    }

    private function pipSize(string $symbol): float
    {
        return str_ends_with($symbol, 'JPY') ? 0.01 : 0.0001;
    }

    private function recordEvent(ForexGridBotConfig $config, string $eventType, array $context = []): void
    {
        ForexGridBotEvent::create([
            'forex_grid_bot_config_id' => $config->id,
            'event_type' => $eventType,
            'context_json' => $context,
            'created_at' => now(),
        ]);
    }
}
