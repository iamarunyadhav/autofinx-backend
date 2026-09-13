<?php

namespace App\Domain\Pepperstone;

use App\Domain\Ops\OperationalBreakerService;
use App\Models\Instrument;
use App\Models\InstrumentMapping;
use App\Models\Order;
use App\Models\PepperstoneBotConfig;
use App\Models\PepperstoneBotState;

/**
 * Composes every pre-trade guard for the Pepperstone Metals & Energy
 * module before RunPepperstoneBotEvaluationJob is allowed to call
 * TradingVenueClientInterface::placeOrder(). Mirrors
 * RunForexGridBotEvaluationJob's cap-before-placeOrder discipline, plus the
 * additional guards this module's spec calls for (margin, spread, daily
 * loss, duplicate-order idempotency, emergency stop).
 *
 * Each check below is independently triggerable: authorizeTrade() short-
 * circuits and returns the FIRST violated reason, but every other input
 * defaults to a value that would not itself trigger a block, so a caller
 * (or test) can flip exactly one condition and observe exactly one reason
 * -- see tests/Unit/Pepperstone/RiskEngineTest.php, one test per reason.
 */
class RiskEngine
{
    public function __construct(
        private readonly OperationalBreakerService $breakers,
        private readonly PositionSizingService $sizing,
        private readonly TradingCostCalculator $costCalculator = new TradingCostCalculator(),
    ) {
    }

    /**
     * @param array $quote ['mid' => float, 'bid' => float, 'ask' => float, ...] shape
     *                      returned by TradingVenueClientInterface::getLatestPrice().
     * @param array $context {
     *   @var float       $proposed_lots            Lot size PositionSizingService already sized this candidate to. Default 0.01.
     *   @var float       $free_margin              Account free margin available. Default PHP_FLOAT_MAX (i.e. "not the thing under test").
     *   @var float       $current_exposure_lots    Sum of lot_size across all currently-open positions for this config. Default 0.
     *   @var float       $current_position_lots    Net open position size for this config. Default 0.
     *   @var float|null  $daily_realized_pnl_pct   Today's realized P&L %, negative = loss. Default null (check skipped unless config sets a limit AND this is provided).
     *   @var string      $client_order_id          AUTOFINX-{SYMBOL}-{SIDE}-{uuid} idempotency key, checked against orders.idempotency_key. Default ''.
     *   @var float|null  $quote_age_seconds        Wall-clock age of the quote just fetched. Default null (freshness check skipped).
     *   @var float       $max_quote_age_seconds    Freshness threshold. Default 30.
     *   @var bool        $emergency_stop           Manual/automated kill flag. Default false.
     *   @var string      $market_regime            One of MarketRegimeDetector's REGIME_* constants. Default TRENDING (never itself blocks).
     *   @var bool        $direction_conflict       True if the composite V3 score and MTFConfirmationEngine disagree on direction. Default false.
     *   @var float       $portfolio_exposure_lots  Sum of filled lots across ALL of the workspace's active baskets (every instrument), from PortfolioRiskEngine. Default 0.
     *   @var float|null  $portfolio_exposure_cap_lots  Workspace-wide exposure cap. Default null (check skipped).
     *   @var float|null  $margin_safety_reserve_floor  Minimum free margin that must remain AFTER the candidate order. Default null (check skipped).
     *   @var float|null  $expected_profit          Expected move's profit estimate, from TradingCostCalculator::expectedProfit(). Default null (cost-clearance check skipped).
     *   @var float|null  $estimated_total_cost     Total spread+slippage+commission cost estimate, from TradingCostCalculator::estimateCost(). Default null (cost-clearance check skipped).
     * }
     *
     * @return array{authorized: bool, reason_code: string|null, reason_detail: string|null}
     */
    public function authorizeTrade(
        ?string $workspaceId,
        PepperstoneBotConfig $config,
        PepperstoneBotState $state,
        Instrument $instrument,
        InstrumentMapping $mapping,
        array $quote,
        array $context = [],
    ): array {
        $context = array_merge([
            'proposed_lots' => 0.01,
            'free_margin' => PHP_FLOAT_MAX,
            'current_exposure_lots' => 0.0,
            'current_position_lots' => 0.0,
            'daily_realized_pnl_pct' => null,
            'client_order_id' => '',
            'quote_age_seconds' => null,
            'max_quote_age_seconds' => 30.0,
            'emergency_stop' => false,
            'market_regime' => MarketRegimeDetector::REGIME_TRENDING,
            'direction_conflict' => false,
            'portfolio_exposure_lots' => 0.0,
            'portfolio_exposure_cap_lots' => null,
            'margin_safety_reserve_floor' => null,
            'expected_profit' => null,
            'estimated_total_cost' => null,
        ], $context);

        if ((bool) $context['emergency_stop']) {
            return $this->block('emergency_stop', 'Emergency stop is active for this bot.');
        }

        if ($this->breakers->isProviderOutageActive($workspaceId, 'pepperstone')) {
            return $this->block('provider_outage', 'Pepperstone provider-outage breaker is active.');
        }

        if (! (bool) $instrument->enabled) {
            return $this->block('instrument_disabled', "Instrument {$instrument->canonical_symbol} is not enabled for trading.");
        }

        $quoteAge = $context['quote_age_seconds'];
        if ($quoteAge !== null && (float) $quoteAge > (float) $context['max_quote_age_seconds']) {
            return $this->block('stale_feed', "Quote is {$quoteAge}s old, exceeds the {$context['max_quote_age_seconds']}s freshness threshold.");
        }

        $mid = (float) ($quote['mid'] ?? 0);
        if ($mid <= 0) {
            return $this->block('stale_feed', 'Non-positive mid price received from venue.');
        }

        if ($config->spread_limit_pips !== null) {
            $spreadPriceDelta = (float) ($quote['ask'] ?? 0) - (float) ($quote['bid'] ?? 0);
            $spreadPips = $spreadPriceDelta / self::PIP_SIZE;
            if ($spreadPips > (float) $config->spread_limit_pips) {
                return $this->block('spread_too_wide', sprintf('Spread %.2f pips exceeds limit %.2f pips.', $spreadPips, (float) $config->spread_limit_pips));
            }
        }

        $proposedLots = (float) $context['proposed_lots'];
        if ($proposedLots <= 0) {
            return $this->block('invalid_size', 'Proposed lot size is zero or negative.');
        }

        $freeMargin = (float) $context['free_margin'];
        if (! $this->sizing->hasSufficientMargin($proposedLots, $mid, $mapping, $freeMargin)) {
            return $this->block('insufficient_margin', 'Insufficient free margin for the proposed lot size.');
        }

        $currentExposure = (float) $context['current_exposure_lots'];
        $maxExposure = (float) $config->max_total_exposure_lots;
        if (($currentExposure + $proposedLots) > $maxExposure) {
            return $this->block('exposure_cap_exceeded', sprintf('Exposure %.4f + %.4f would exceed cap %.4f lots.', $currentExposure, $proposedLots, $maxExposure));
        }

        $currentPosition = (float) $context['current_position_lots'];
        $maxPosition = (float) $config->max_position_lots;
        if (($currentPosition + $proposedLots) > $maxPosition) {
            return $this->block('position_cap_exceeded', sprintf('Position %.4f + %.4f would exceed cap %.4f lots.', $currentPosition, $proposedLots, $maxPosition));
        }

        $activeLevels = (int) $state->active_levels_count;
        if ($activeLevels >= (int) $config->max_grid_levels) {
            return $this->block('level_cap_exceeded', "Active levels {$activeLevels} already at/over cap {$config->max_grid_levels}.");
        }

        if ($config->daily_loss_limit_pct !== null && $context['daily_realized_pnl_pct'] !== null) {
            $dailyPnlPct = (float) $context['daily_realized_pnl_pct'];
            $limit = abs((float) $config->daily_loss_limit_pct);
            if ($dailyPnlPct <= -$limit) {
                return $this->block('daily_loss_limit', sprintf('Daily realized P&L %.2f%% breaches limit -%.2f%%.', $dailyPnlPct, $limit));
            }
        }

        $clientOrderId = (string) $context['client_order_id'];
        if ($clientOrderId !== '' && Order::query()->where('idempotency_key', $clientOrderId)->exists()) {
            return $this->block('duplicate_order', "client_order_id {$clientOrderId} has already been submitted.");
        }

        // ------------------------------------------------------------
        // Basket-model additions (approved MTF/Basket plan, §7). Appended
        // to the end of the existing check ladder rather than
        // restructuring it -- same first-fail-wins shape, extended.
        // ------------------------------------------------------------

        $regime = (string) $context['market_regime'];
        if (in_array($regime, [MarketRegimeDetector::REGIME_CHAOTIC, MarketRegimeDetector::REGIME_LOW_LIQUIDITY], true)) {
            return $this->block('regime_blocked', "Market regime {$regime} blocks new trades.");
        }

        if ((bool) $context['direction_conflict']) {
            return $this->block('direction_conflict', 'V3 composite direction conflicts with MTF confirmation direction.');
        }

        $portfolioCap = $context['portfolio_exposure_cap_lots'];
        if ($portfolioCap !== null) {
            $portfolioExposure = (float) $context['portfolio_exposure_lots'];
            if (($portfolioExposure + $proposedLots) > (float) $portfolioCap) {
                return $this->block('portfolio_exposure_cap_exceeded', sprintf(
                    'Portfolio exposure %.4f + %.4f would exceed workspace-wide cap %.4f lots.',
                    $portfolioExposure,
                    $proposedLots,
                    (float) $portfolioCap,
                ));
            }
        }

        $marginReserveFloor = $context['margin_safety_reserve_floor'];
        if ($marginReserveFloor !== null) {
            $requiredMargin = $this->sizing->requiredMargin($proposedLots, $mid, $mapping);
            $projectedFreeMargin = $freeMargin - $requiredMargin;
            if ($projectedFreeMargin < (float) $marginReserveFloor) {
                return $this->block('margin_reserve_breached', sprintf(
                    'Projected free margin %.2f after this trade would fall below the safety reserve floor %.2f.',
                    $projectedFreeMargin,
                    (float) $marginReserveFloor,
                ));
            }
        }

        $expectedProfit = $context['expected_profit'];
        $estimatedCost = $context['estimated_total_cost'];
        if ($expectedProfit !== null && $estimatedCost !== null) {
            if (! $this->costCalculator->clearsCost((float) $expectedProfit, (float) $estimatedCost)) {
                return $this->block('cost_not_cleared', sprintf(
                    'Expected profit %.4f does not clear estimated total cost %.4f.',
                    (float) $expectedProfit,
                    (float) $estimatedCost,
                ));
            }
        }

        return ['authorized' => true, 'reason_code' => null, 'reason_detail' => null];
    }

    /**
     * Documented simplification: this module expresses spread limits in
     * "pips" using a fixed 0.01 price-per-pip convention for every
     * instrument it trades (gold/silver/platinum/crude), matching the cent-
     * denominated quoting convention common to metals/energy CFDs. Not a
     * claim about Pepperstone's actual real pip convention, which is
     * unconfirmed pending a real account (see PepperstoneBrokerAdapter).
     */
    private const PIP_SIZE = 0.01;

    private function block(string $code, string $detail): array
    {
        return ['authorized' => false, 'reason_code' => $code, 'reason_detail' => $detail];
    }
}
