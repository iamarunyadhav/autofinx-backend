<?php

namespace App\Domain\Pepperstone;

use App\Models\PepperstoneBasket;

/**
 * Priority-ordered exit checks for one Pepperstone basket, per the approved
 * plan's §8. Checks run in a fixed order and the FIRST one that fires wins
 * (mirrors RiskEngine::authorizeTrade()'s own first-fail-wins convention) --
 * every check is independently triggerable in tests via its own context
 * key, with every other input defaulted to a value that would not itself
 * trigger a close.
 *
 * Only a simple, fixed take-profit target is implemented for now (the
 * plan's explicit "default should remain simple until validated"
 * instruction) -- evaluate()'s signature and this class's shape leave room
 * for a later, explicitly-configurable trailing-floor variant without a
 * rewrite (it would slot in as one more context-driven check, or a
 * strategy object swapped in via the constructor).
 */
class BasketExitEngine
{
    public const REASON_HARD_STOP_LOSS = 'hard_stop_loss';
    public const REASON_TAKE_PROFIT = 'take_profit';
    public const REASON_V3_REVERSAL = 'v3_direction_reversal';
    public const REASON_MTF_CONFLICT = 'mtf_conflict';
    public const REASON_REGIME_CHAOTIC = 'regime_chaotic';
    public const REASON_MARGIN_DETERIORATION = 'margin_deterioration';
    public const REASON_STALE_FEED_OR_BROKER_ISSUE = 'stale_feed_or_broker_issue';

    /**
     * @param  array  $mtf  Structured MTFConfirmationEngine::confirm() result.
     * @param  array  $context {
     *   @var float       $floating_pnl              Current basket floating P&L (quote-currency). Default 0.0.
     *   @var string|null $v3_direction              'BUY'|'SELL'|null. Default null (check skipped).
     *   @var float|null  $margin_level_pct          Current account margin level %. Default null (check skipped).
     *   @var float       $margin_level_floor_pct    Minimum acceptable margin level %. Default 0.0 (never breached).
     *   @var bool        $feed_stale                Default false.
     *   @var bool        $broker_issue              Default false.
     * }
     *
     * @return array{should_close: bool, reason: string|null}
     */
    public function evaluate(PepperstoneBasket $basket, array $mtf, string $regime, array $context = []): array
    {
        $context = array_merge([
            'floating_pnl' => 0.0,
            'v3_direction' => null,
            'margin_level_pct' => null,
            'margin_level_floor_pct' => 0.0,
            'feed_stale' => false,
            'broker_issue' => false,
        ], $context);

        $floatingPnl = (float) $context['floating_pnl'];

        if ($basket->maximum_allowed_loss !== null && $floatingPnl <= -abs((float) $basket->maximum_allowed_loss)) {
            return $this->close(self::REASON_HARD_STOP_LOSS);
        }

        if ($basket->take_profit_target !== null && $floatingPnl >= (float) $basket->take_profit_target) {
            return $this->close(self::REASON_TAKE_PROFIT);
        }

        $v3Direction = $context['v3_direction'] !== null ? strtolower((string) $context['v3_direction']) : null;
        if ($v3Direction !== null && $v3Direction !== strtolower((string) $basket->direction)) {
            return $this->close(self::REASON_V3_REVERSAL);
        }

        $mtfDirection = strtoupper((string) ($mtf['direction'] ?? 'NEUTRAL'));
        if ($mtfDirection !== 'NEUTRAL' && $mtfDirection !== strtoupper((string) $basket->direction)) {
            return $this->close(self::REASON_MTF_CONFLICT);
        }

        if (strtoupper($regime) === MarketRegimeDetector::REGIME_CHAOTIC) {
            return $this->close(self::REASON_REGIME_CHAOTIC);
        }

        if ($context['margin_level_pct'] !== null && (float) $context['margin_level_pct'] < (float) $context['margin_level_floor_pct']) {
            return $this->close(self::REASON_MARGIN_DETERIORATION);
        }

        if ((bool) $context['feed_stale'] || (bool) $context['broker_issue']) {
            return $this->close(self::REASON_STALE_FEED_OR_BROKER_ISSUE);
        }

        return ['should_close' => false, 'reason' => null];
    }

    private function close(string $reason): array
    {
        return ['should_close' => true, 'reason' => $reason];
    }
}
