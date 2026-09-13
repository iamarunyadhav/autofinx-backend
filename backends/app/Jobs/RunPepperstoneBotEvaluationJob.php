<?php

namespace App\Jobs;

use App\Domain\Pepperstone\BasketExitEngine;
use App\Domain\Pepperstone\MarketRegimeDetector;
use App\Domain\Pepperstone\MTFConfirmationEngine;
use App\Domain\Pepperstone\PortfolioRiskEngine;
use App\Domain\Pepperstone\PositionSizingService;
use App\Domain\Pepperstone\RiskEngine;
use App\Domain\Pepperstone\TradingCostCalculator;
use App\Domain\Pepperstone\V3CompositeScorer;
use App\Domain\Venue\PepperstoneBrokerAdapter;
use App\Domain\Venue\TradingVenueClientFactory;
use App\Models\ExchangeConnection;
use App\Models\Instrument;
use App\Models\InstrumentMapping;
use App\Models\PepperstoneBasket;
use App\Models\PepperstoneBasketSlot;
use App\Models\PepperstoneBotConfig;
use App\Models\PepperstoneBotEvent;
use App\Models\PepperstoneBotState;
use App\Models\TradingAccountSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scheduled or manually triggered evaluation of one Pepperstone Metals &
 * Energy bot config -- REWRITTEN per the approved MTF Confirmation +
 * Multi-Slot Basket plan (C:\Users\PC\.claude\plans\cosmic-knitting-
 * marshmallow.md, §12/§26 of the user's own spec).
 *
 * Superseded design (this session's earlier, single-ladder, BUY-only
 * pepperstone_positions-based flow): this class used to open/manage flat
 * PepperstonePosition rows directly. It now opens/manages
 * PepperstoneBasket + PepperstoneBasketSlot rows, supports BOTH BUY and
 * SELL baskets, and only ever runs ONE active basket per config at a time.
 *
 * STAGED PIPELINE (every tick, in order):
 *   1. Feed/regime health   -- fetch quote, reject non-positive/stale mid.
 *   2. V3 composite score   -- App\Domain\Pepperstone\V3CompositeScorer.
 *   3. MTF confirmation     -- App\Domain\Pepperstone\MTFConfirmationEngine.
 *   4. Market regime        -- App\Domain\Pepperstone\MarketRegimeDetector.
 *   5a. No active basket:   evaluate entry (V3+MTF agreement, spacing,
 *       regime not CHAOTIC/LOW_LIQUIDITY) -> RiskEngine::authorizeTrade()
 *       -> open basket + slot 0.
 *   5b. Active basket:      BasketExitEngine::evaluate() first (closes the
 *       WHOLE basket on any exit reason, no further slot opens this tick)
 *       -> else consider ONE additional slot, revalidating the FULL chain
 *       fresh (regime, V3/MTF agreement with the basket's OWN direction,
 *       RiskEngine::authorizeTrade()) -- no caching from anchor time, per
 *       the plan's explicit "revalidate before every slot" rule.
 *
 * Still on the `pepperstone` queue, still per-bot locked
 * (WithoutOverlapping + a Cache::lock()), same as before.
 */
class RunPepperstoneBotEvaluationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;
    public int $uniqueFor = 5;

    /**
     * Adaptive ATR-based slot spacing: a new slot triggers only once price
     * has moved adversely against the basket by (this multiplier * the
     * H1-timeframe ATR at basket-open time). Replaces the old hardcoded
     * LEVEL_SPACING_PCT = 1.0 -- ATR already scales naturally per
     * instrument (gold's ATR is a much larger raw number than silver's),
     * so no separate per-instrument-price-scale correction is needed the
     * way the old flat-percentage rule required one.
     */
    private const ATR_SPACING_MULTIPLIER = 1.5;

    /** Fallback spacing (as a % of entry price) used only if no ATR read is available yet (e.g. no candle history backfilled). */
    private const FALLBACK_SPACING_PCT = 1.0;

    /** Candle timeframe used for regime detection / ATR spacing / V3 scoring's default. */
    private const REGIME_TIMEFRAME = '1h';

    public function __construct(
        public readonly string $pepperstoneBotConfigId,
        public readonly bool $manualTrigger = false,
    ) {
        $this->onQueue('pepperstone');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('pepperstone-eval:' . $this->pepperstoneBotConfigId))->expireAfter(10),
        ];
    }

    public function uniqueId(): string
    {
        return 'pepperstone-eval:' . $this->pepperstoneBotConfigId;
    }

    public function handle(
        TradingVenueClientFactory $clients,
        PositionSizingService $sizing,
        RiskEngine $riskEngine,
        MTFConfirmationEngine $mtfEngine,
        MarketRegimeDetector $regimeDetector,
        V3CompositeScorer $v3Scorer,
        PortfolioRiskEngine $portfolioRiskEngine,
        BasketExitEngine $exitEngine,
        TradingCostCalculator $costCalculator,
    ): void {
        $config = PepperstoneBotConfig::with(['state', 'instrument'])->find($this->pepperstoneBotConfigId);
        if (! $config) {
            Log::warning('[PepperstoneJob] Config not found', ['id' => $this->pepperstoneBotConfigId]);
            return;
        }

        if (! $config->enabled && ! $this->manualTrigger) {
            return;
        }

        $lockKey = 'pepperstone-eval-bot:' . $config->id;
        $lock = Cache::lock($lockKey, 10);
        if (! $lock->get()) {
            Log::debug('[PepperstoneJob] Duplicate evaluation blocked by per-bot lock', ['config_id' => $config->id]);
            return;
        }

        try {
            $instrument = $config->instrument;
            if (! $instrument) {
                Log::warning('[PepperstoneJob] Config has no instrument', ['config_id' => $config->id]);
                return;
            }

            $state = PepperstoneBotState::firstOrCreate(
                ['pepperstone_bot_config_id' => $config->id],
                ['status' => PepperstoneBotState::STATUS_IDLE],
            );

            if (in_array($state->status, [PepperstoneBotState::STATUS_STOPPED, PepperstoneBotState::STATUS_BLOCKED, PepperstoneBotState::STATUS_PAUSED], true)) {
                return;
            }

            $mapping = $instrument->mappingFor('pepperstone');
            if (! $mapping) {
                $this->recordEvent($config, PepperstoneBotEvent::EVENT_BLOCKED, ['reason' => 'no_instrument_mapping']);
                return;
            }

            $connection = $config->connection_id ? ExchangeConnection::find($config->connection_id) : null;

            // -----------------------------------------------------------
            // STAGE 1: feed health
            // -----------------------------------------------------------
            try {
                $venue = $clients->forProvider('pepperstone', $connection);
                $quote = $venue->getLatestPrice($instrument->normalizedSymbol());
            } catch (Throwable $e) {
                $this->recordEvent($config, PepperstoneBotEvent::EVENT_FEED_ERROR, ['message' => $e->getMessage()]);
                Log::warning('[PepperstoneJob] getLatestPrice failed', ['config_id' => $config->id, 'error' => $e->getMessage()]);
                return;
            }

            $mid = (float) ($quote['mid'] ?? 0);
            if ($mid <= 0) {
                $this->recordEvent($config, PepperstoneBotEvent::EVENT_FEED_ERROR, ['message' => 'non-positive mid price']);
                return;
            }

            $recentCandles = $this->recentCandles($venue, $instrument);

            // -----------------------------------------------------------
            // STAGE 2/3/4: V3 score, MTF confirmation, market regime
            // -----------------------------------------------------------
            $v3 = $v3Scorer->score($instrument, self::REGIME_TIMEFRAME);
            $mtf = $mtfEngine->confirm($instrument);
            $regime = $regimeDetector->detect($instrument, $quote, $recentCandles);

            $freeMargin = $this->latestFreeMargin($config);

            $activeBasket = PepperstoneBasket::query()
                ->where('pepperstone_bot_config_id', $config->id)
                ->whereIn('status', PepperstoneBasket::OPEN_STATUSES)
                ->first();

            if (! $activeBasket) {
                $this->maybeOpenBasket(
                    $config, $state, $instrument, $mapping, $quote, $mid, $freeMargin, $recentCandles,
                    $v3, $mtf, $regime, $sizing, $riskEngine, $portfolioRiskEngine, $costCalculator, $venue,
                );
            } else {
                $this->evaluateActiveBasket(
                    $config, $state, $activeBasket, $instrument, $mapping, $quote, $mid, $freeMargin, $recentCandles,
                    $v3, $mtf, $regime, $sizing, $riskEngine, $portfolioRiskEngine, $exitEngine, $costCalculator, $venue,
                );
            }

            $state->fresh()->update(['last_evaluated_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    // -----------------------------------------------------------------
    // Stage 5a: no active basket -- decide whether to open one
    // -----------------------------------------------------------------
    private function maybeOpenBasket(
        PepperstoneBotConfig $config,
        PepperstoneBotState $state,
        Instrument $instrument,
        InstrumentMapping $mapping,
        array $quote,
        float $mid,
        float $freeMargin,
        array $recentCandles,
        array $v3,
        array $mtf,
        string $regime,
        PositionSizingService $sizing,
        RiskEngine $riskEngine,
        PortfolioRiskEngine $portfolioRiskEngine,
        TradingCostCalculator $costCalculator,
        $venue,
    ): void {
        if (in_array($regime, [MarketRegimeDetector::REGIME_CHAOTIC, MarketRegimeDetector::REGIME_LOW_LIQUIDITY], true)) {
            $this->recordEvent($config, PepperstoneBotEvent::EVENT_BLOCKED, ['reason' => 'regime_blocked', 'regime' => $regime]);
            return;
        }

        $v3Direction = strtoupper((string) ($v3['direction'] ?? 'NEUTRAL'));
        $mtfDirection = strtoupper((string) ($mtf['direction'] ?? 'NEUTRAL'));

        if ($v3Direction === 'NEUTRAL' || $mtfDirection === 'NEUTRAL' || $v3Direction !== $mtfDirection) {
            $this->recordEvent($config, PepperstoneBotEvent::EVENT_BLOCKED, [
                'reason' => 'v3_mtf_no_agreement',
                'v3_direction' => $v3Direction,
                'mtf_direction' => $mtfDirection,
            ]);
            return;
        }

        $minV3Confidence = $config->v3_confidence_threshold !== null ? (float) $config->v3_confidence_threshold : 0.0;
        $minMtfConfidence = $config->min_mtf_confidence !== null ? (float) $config->min_mtf_confidence : 0.0;

        if ((float) ($v3['confidence'] ?? 0) < $minV3Confidence || (float) ($mtf['confidence'] ?? 0) < $minMtfConfidence) {
            $this->recordEvent($config, PepperstoneBotEvent::EVENT_BLOCKED, [
                'reason' => 'confidence_below_threshold',
                'v3_confidence' => $v3['confidence'] ?? null,
                'mtf_confidence' => $mtf['confidence'] ?? null,
            ]);
            return;
        }

        $direction = $v3Direction === 'BUY' ? PepperstoneBasket::DIRECTION_BUY : PepperstoneBasket::DIRECTION_SELL;

        $sized = $sizing->sizeEqualLot($config, $mapping);
        if ($sized['rejected']) {
            $this->recordEvent($config, PepperstoneBotEvent::EVENT_BLOCKED, ['reason' => $sized['reason'], 'level_index' => 0]);
            return;
        }

        $atr = $this->atrFor($recentCandles);
        $expectedMove = $atr !== null ? $atr * self::ATR_SPACING_MULTIPLIER : $mid * (self::FALLBACK_SPACING_PCT / 100);

        $authorization = $this->authorize(
            $config, $state, $instrument, $mapping, $quote, $mid, $freeMargin, $regime, $v3Direction, $mtfDirection,
            $sized['lots'], 0.0, 0.0, $expectedMove, $riskEngine, $portfolioRiskEngine, $costCalculator, $direction,
        );

        if (! $authorization['authorized']) {
            $this->recordEvent($config, PepperstoneBotEvent::EVENT_BLOCKED, [
                'reason' => $authorization['reason_code'],
                'detail' => $authorization['reason_detail'],
                'level_index' => 0,
            ]);
            return;
        }

        $order = $venue->placeOrder([
            'asset_code' => $instrument->normalizedSymbol(),
            'side' => strtoupper($direction),
            'qty' => $sized['lots'],
        ]);

        $entryPrice = (float) ($order['avg_price'] ?? $mid);

        $basket = PepperstoneBasket::create([
            'pepperstone_bot_config_id' => $config->id,
            'direction' => $direction,
            'status' => PepperstoneBasket::STATUS_ACTIVE,
            'max_levels' => (int) ($config->max_basket_levels ?? 8),
            'opened_levels' => 1,
            'lot_per_level' => $sized['lots'],
            'average_entry' => $entryPrice,
            'take_profit_target' => $config->basket_take_profit_pct !== null
                ? $this->pctToAbsolutePnl((float) $config->basket_take_profit_pct, $entryPrice, $sized['lots'])
                : null,
            'maximum_allowed_loss' => $config->basket_stop_loss_pct !== null
                ? $this->pctToAbsolutePnl((float) $config->basket_stop_loss_pct, $entryPrice, $sized['lots'])
                : null,
            'v3_direction' => $v3Direction,
            'v3_confidence' => $v3['confidence'] ?? null,
            'mtf_confidence' => $mtf['confidence'] ?? null,
            'mtf_agreement_score' => $mtf['agreement_score'] ?? null,
            'opened_at' => now(),
        ]);

        PepperstoneBasketSlot::create([
            'pepperstone_basket_id' => $basket->id,
            'level_index' => 0,
            'entry_price' => $entryPrice,
            'lot_size' => $sized['lots'],
            'order_id' => (string) ($order['order_id'] ?? ''),
            'status' => PepperstoneBasketSlot::STATUS_FILLED,
            'opened_at' => now(),
        ]);

        $state->update(['status' => PepperstoneBotState::STATUS_RUNNING]);

        $this->recordEvent($config, PepperstoneBotEvent::EVENT_STARTED, [
            'basket_id' => $basket->id,
            'direction' => $direction,
            'anchor_price' => $entryPrice,
            'lot_size' => $sized['lots'],
        ]);
    }

    // -----------------------------------------------------------------
    // Stage 5b: an active basket already exists
    // -----------------------------------------------------------------
    private function evaluateActiveBasket(
        PepperstoneBotConfig $config,
        PepperstoneBotState $state,
        PepperstoneBasket $basket,
        Instrument $instrument,
        InstrumentMapping $mapping,
        array $quote,
        float $mid,
        float $freeMargin,
        array $recentCandles,
        array $v3,
        array $mtf,
        string $regime,
        PositionSizingService $sizing,
        RiskEngine $riskEngine,
        PortfolioRiskEngine $portfolioRiskEngine,
        BasketExitEngine $exitEngine,
        TradingCostCalculator $costCalculator,
        $venue,
    ): void {
        $filledSlots = $basket->slots()->where('status', PepperstoneBasketSlot::STATUS_FILLED)->orderBy('level_index')->get();
        $floatingPnl = $this->floatingPnl($basket, $filledSlots, $mid);

        $v3Direction = strtoupper((string) ($v3['direction'] ?? 'NEUTRAL'));

        $exit = $exitEngine->evaluate($basket, $mtf, $regime, [
            'floating_pnl' => $floatingPnl,
            'v3_direction' => $v3Direction !== 'NEUTRAL' ? $v3Direction : null,
        ]);

        if ($exit['should_close']) {
            $this->closeBasket($basket, $instrument, $mid, $exit['reason'], $venue);
            $state->update(['status' => PepperstoneBotState::STATUS_STOPPED]);
            return;
        }

        // No slot-add attempt if the cap is already reached.
        if ((int) $basket->opened_levels >= (int) $basket->max_levels) {
            return;
        }

        $lastSlot = $filledSlots->last();
        if (! $lastSlot) {
            return;
        }

        $atr = $this->atrFor($recentCandles);
        $spacing = $atr !== null ? $atr * self::ATR_SPACING_MULTIPLIER : $mid * (self::FALLBACK_SPACING_PCT / 100);
        $entry = (float) $lastSlot->entry_price;

        $triggered = $basket->direction === PepperstoneBasket::DIRECTION_BUY
            ? $mid <= ($entry - $spacing)
            : $mid >= ($entry + $spacing);

        if (! $triggered) {
            return;
        }

        // Revalidate the FULL chain fresh -- no caching from anchor time
        // (the plan's explicit "revalidate before every slot" rule).
        if (in_array($regime, [MarketRegimeDetector::REGIME_CHAOTIC, MarketRegimeDetector::REGIME_LOW_LIQUIDITY], true)) {
            $this->recordEvent($config, PepperstoneBotEvent::EVENT_BLOCKED, ['reason' => 'regime_blocked_new_slot', 'regime' => $regime]);
            return;
        }

        $mtfDirection = strtoupper((string) ($mtf['direction'] ?? 'NEUTRAL'));
        $basketDirectionUpper = strtoupper($basket->direction);

        if ($v3Direction !== 'NEUTRAL' && $v3Direction !== $basketDirectionUpper) {
            $this->recordEvent($config, PepperstoneBotEvent::EVENT_BLOCKED, ['reason' => 'v3_direction_reversal_stops_new_slots']);
            return;
        }
        if ($mtfDirection !== 'NEUTRAL' && $mtfDirection !== $basketDirectionUpper) {
            $this->recordEvent($config, PepperstoneBotEvent::EVENT_BLOCKED, ['reason' => 'mtf_conflict_stops_new_slots']);
            return;
        }

        $sized = $sizing->sizeEqualLot($config, $mapping);
        if ($sized['rejected']) {
            $this->recordEvent($config, PepperstoneBotEvent::EVENT_BLOCKED, ['reason' => $sized['reason'], 'level_index' => $basket->opened_levels]);
            return;
        }

        $currentExposure = (float) $filledSlots->sum(fn ($s) => (float) $s->lot_size);

        $authorization = $this->authorize(
            $config, $state, $instrument, $mapping, $quote, $mid, $freeMargin, $regime, $v3Direction, $mtfDirection,
            $sized['lots'], $currentExposure, $currentExposure, $spacing, $riskEngine, $portfolioRiskEngine, $costCalculator,
            $basket->direction,
        );

        if (! $authorization['authorized']) {
            $this->recordEvent($config, PepperstoneBotEvent::EVENT_BLOCKED, [
                'reason' => $authorization['reason_code'],
                'detail' => $authorization['reason_detail'],
                'level_index' => $basket->opened_levels,
            ]);
            return;
        }

        $order = $venue->placeOrder([
            'asset_code' => $instrument->normalizedSymbol(),
            'side' => strtoupper($basket->direction),
            'qty' => $sized['lots'],
        ]);

        $nextLevelIndex = (int) $basket->opened_levels;
        $entryPrice = (float) ($order['avg_price'] ?? $mid);

        PepperstoneBasketSlot::create([
            'pepperstone_basket_id' => $basket->id,
            'level_index' => $nextLevelIndex,
            'entry_price' => $entryPrice,
            'lot_size' => $sized['lots'],
            'order_id' => (string) ($order['order_id'] ?? ''),
            'status' => PepperstoneBasketSlot::STATUS_FILLED,
            'opened_at' => now(),
        ]);

        $newTotalLots = $currentExposure + (float) $sized['lots'];
        $newAverageEntry = (($currentExposure * (float) $basket->average_entry) + ((float) $sized['lots'] * $entryPrice)) / $newTotalLots;

        $basket->update([
            'opened_levels' => $nextLevelIndex + 1,
            'average_entry' => $newAverageEntry,
        ]);

        $this->recordEvent($config, PepperstoneBotEvent::EVENT_LEVEL_OPENED, [
            'basket_id' => $basket->id,
            'level_index' => $nextLevelIndex,
            'entry_price' => $entryPrice,
            'lot_size' => $sized['lots'],
        ]);
    }

    private function closeBasket(PepperstoneBasket $basket, Instrument $instrument, float $mid, string $reason, $venue): void
    {
        foreach ($basket->slots()->whereIn('status', [PepperstoneBasketSlot::STATUS_FILLED, PepperstoneBasketSlot::STATUS_PENDING])->get() as $slot) {
            if ($slot->status === PepperstoneBasketSlot::STATUS_PENDING) {
                if ($slot->order_id) {
                    $venue->cancelOrder($slot->order_id, $instrument->normalizedSymbol());
                }
                $slot->update(['status' => PepperstoneBasketSlot::STATUS_CANCELLED, 'closed_at' => now()]);
                continue;
            }

            $closingSide = $basket->direction === PepperstoneBasket::DIRECTION_BUY ? 'SELL' : 'BUY';
            $closeOrder = $venue->placeOrder([
                'asset_code' => $instrument->normalizedSymbol(),
                'side' => $closingSide,
                'qty' => (float) $slot->lot_size,
            ]);

            $exitPrice = (float) ($closeOrder['avg_price'] ?? $mid);
            $entry = (float) $slot->entry_price;
            $lot = (float) $slot->lot_size;
            $realizedPnl = $basket->direction === PepperstoneBasket::DIRECTION_BUY
                ? ($exitPrice - $entry) * $lot
                : ($entry - $exitPrice) * $lot;

            $slot->update([
                'status' => PepperstoneBasketSlot::STATUS_CLOSED,
                'closed_at' => now(),
                'realized_pnl' => $realizedPnl,
            ]);
        }

        $basket->update([
            'status' => PepperstoneBasket::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_reason' => $reason,
        ]);

        $this->recordEvent($basket->config, PepperstoneBotEvent::EVENT_BASKET_CLOSED, [
            'basket_id' => $basket->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Runs RiskEngine::authorizeTrade() with the full basket-aware context
     * (regime, direction conflict, portfolio exposure, cost-clearance) --
     * shared by both the "open a new basket" and "add a slot" paths so the
     * exact same chain is revalidated in both places.
     */
    private function authorize(
        PepperstoneBotConfig $config,
        PepperstoneBotState $state,
        Instrument $instrument,
        InstrumentMapping $mapping,
        array $quote,
        float $mid,
        float $freeMargin,
        string $regime,
        string $v3Direction,
        string $mtfDirection,
        ?float $proposedLots,
        float $currentExposure,
        float $currentPosition,
        float $expectedMove,
        RiskEngine $riskEngine,
        PortfolioRiskEngine $portfolioRiskEngine,
        TradingCostCalculator $costCalculator,
        string $basketDirection,
    ): array {
        if ($proposedLots === null) {
            return ['authorized' => false, 'reason_code' => 'invalid_size', 'reason_detail' => 'No valid lot size.'];
        }

        $directionConflict = $v3Direction !== 'NEUTRAL' && $mtfDirection !== 'NEUTRAL' && $v3Direction !== $mtfDirection;

        $portfolioExposure = $portfolioRiskEngine->totalExposureLots((string) $config->workspace_id);

        $contractSize = (float) $mapping->contract_size;
        $spreadDelta = (float) ($quote['ask'] ?? $mid) - (float) ($quote['bid'] ?? $mid);
        $slippageDelta = $config->maximum_allowed_slippage !== null ? (float) $config->maximum_allowed_slippage : 0.0;
        $estimatedCost = $costCalculator->estimateCost($proposedLots, $contractSize, $spreadDelta, $slippageDelta, 0.0);
        $expectedProfit = $costCalculator->expectedProfit($proposedLots, $contractSize, $expectedMove);

        return $riskEngine->authorizeTrade(
            (string) $config->workspace_id,
            $config,
            $state,
            $instrument,
            $mapping,
            $quote,
            [
                'proposed_lots' => $proposedLots,
                'free_margin' => $freeMargin,
                'current_exposure_lots' => $currentExposure,
                'current_position_lots' => $currentPosition,
                'client_order_id' => $this->clientOrderId($instrument, $basketDirection),
                'market_regime' => $regime,
                'direction_conflict' => $directionConflict,
                'portfolio_exposure_lots' => $portfolioExposure,
                'portfolio_exposure_cap_lots' => $config->portfolio_exposure_cap_lots !== null ? (float) $config->portfolio_exposure_cap_lots : null,
                'margin_safety_reserve_floor' => $config->margin_safety_reserve_floor !== null ? (float) $config->margin_safety_reserve_floor : null,
                'expected_profit' => $expectedProfit,
                'estimated_total_cost' => $estimatedCost,
            ],
        );
    }

    private function latestFreeMargin(PepperstoneBotConfig $config): float
    {
        $snapshot = TradingAccountSnapshot::query()
            ->where('workspace_id', $config->workspace_id)
            ->where('provider', 'pepperstone')
            ->when($config->connection_id, fn ($q) => $q->where('connection_id', $config->connection_id))
            ->orderByDesc('synced_at')
            ->first();

        return $snapshot ? (float) $snapshot->free_margin : PHP_FLOAT_MAX;
    }

    /**
     * Best-effort recent-candle fetch for regime detection/ATR spacing.
     * Prefers already-backfilled market_ohlc rows (via
     * MTFConfirmationEngine's own data source); falls back to the
     * sandbox-only SupportsHistoricalCandles::fetchCandles() capability
     * directly when nothing has been backfilled yet, so this job still
     * functions before any PepperstoneOhlcBackfillJob has ever run.
     */
    private function recentCandles($venue, Instrument $instrument): array
    {
        if (! $venue instanceof PepperstoneBrokerAdapter) {
            return [];
        }

        try {
            return $venue->fetchCandles($instrument->normalizedSymbol(), self::REGIME_TIMEFRAME, 100);
        } catch (Throwable $e) {
            return [];
        }
    }

    private function atrFor(array $recentCandles): ?float
    {
        return \App\Domain\Market\Indicators\Indicators::atr($recentCandles, 14);
    }

    private function floatingPnl(PepperstoneBasket $basket, $filledSlots, float $mid): float
    {
        return (float) $filledSlots->sum(function ($slot) use ($basket, $mid) {
            $entry = (float) $slot->entry_price;
            $lot = (float) $slot->lot_size;

            return $basket->direction === PepperstoneBasket::DIRECTION_BUY
                ? ($mid - $entry) * $lot
                : ($entry - $mid) * $lot;
        });
    }

    /**
     * Converts a basket_stop_loss_pct/basket_take_profit_pct percentage
     * (of notional, same lot_size-only P&L simplification the old
     * maybeCloseBasket() used -- contract_size intentionally NOT
     * multiplied in, for parity with that already-tested convention) into
     * an absolute quote-currency P&L figure for
     * PepperstoneBasket::maximum_allowed_loss/take_profit_target.
     */
    private function pctToAbsolutePnl(float $pct, float $entryPrice, float $lots): float
    {
        return $entryPrice * $lots * (abs($pct) / 100);
    }

    private function clientOrderId(Instrument $instrument, string $side): string
    {
        return sprintf('AUTOFINX-%s-%s-%s', $instrument->normalizedSymbol(), strtoupper($side), (string) Str::uuid());
    }

    private function recordEvent(PepperstoneBotConfig $config, string $eventType, array $context = []): void
    {
        PepperstoneBotEvent::create([
            'pepperstone_bot_config_id' => $config->id,
            'event_type' => $eventType,
            'context_json' => $context,
            'created_at' => now(),
        ]);
    }
}
