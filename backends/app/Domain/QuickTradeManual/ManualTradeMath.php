<?php

namespace App\Domain\QuickTradeManual;

use App\Domain\Trading\LeverageValidationService;

/**
 * Pure math for Manual SL/TP previews and entry validation. No I/O.
 *
 * Conventions (all verified by tests/Unit/QuickTradeManual/ManualTradeMathTest):
 *  - stake = margin; notional = stake x leverage; quantity = floor-to-step(notional / price).
 *  - SL % is PRICE-MOVEMENT %: LONG trigger = entry x (1 - sl/100), SHORT = entry x (1 + sl/100)
 *    (entry 80,000 and 1 % => 79,200 / 80,800). Margin loss % = price % x leverage (shown, not an input).
 *  - Trigger prices are rounded to the tick TOWARD entry, so the stop is never farther than asked
 *    and TP is never farther than the displayed profit.
 */
final class ManualTradeMath
{
    /** @return int number of decimals in a tick/step such as 0.10000000 -> 1 */
    public static function decimalsOf(float $step): int
    {
        $s = rtrim(rtrim(number_format($step, 12, '.', ''), '0'), '.');
        $pos = strpos($s, '.');

        return $pos === false ? 0 : strlen($s) - $pos - 1;
    }

    public static function formatToStep(float $value, float $step): string
    {
        return number_format($value, self::decimalsOf($step), '.', '');
    }

    public static function floorToStep(float $value, float $step): float
    {
        if ($step <= 0) {
            return $value;
        }
        $q = $value / $step;
        $r = round($q);
        $n = abs($q - $r) < 1e-7 ? $r : floor($q);

        return round($n * $step, self::decimalsOf($step));
    }

    public static function ceilToStep(float $value, float $step): float
    {
        if ($step <= 0) {
            return $value;
        }
        $q = $value / $step;
        $r = round($q);
        $n = abs($q - $r) < 1e-7 ? $r : ceil($q);

        return round($n * $step, self::decimalsOf($step));
    }

    public static function slTrigger(string $direction, float $entry, float $slPct, float $tick): float
    {
        return $direction === 'long'
            ? self::ceilToStep($entry * (1 - $slPct / 100), $tick)    // toward entry = up
            : self::floorToStep($entry * (1 + $slPct / 100), $tick);  // toward entry = down
    }

    public static function tpTrigger(string $direction, float $entry, float $tpPct, float $tick): float
    {
        return $direction === 'long'
            ? self::floorToStep($entry * (1 + $tpPct / 100), $tick)   // toward entry = down
            : self::ceilToStep($entry * (1 - $tpPct / 100), $tick);   // toward entry = up
    }

    public static function quantity(float $stake, float $leverage, float $price, float $step): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        return self::floorToStep(($stake * $leverage) / $price, $step);
    }

    /**
     * Full pre-trade numbers + blocking errors + warnings for the confirmation screen and for entry validation.
     *
     * @param  array{direction: string, stake: float, leverage: float, price: float, sl_pct: ?float, tp_pct: ?float, no_stop_loss?: bool,
     *         tick_size: float, step_size: float, min_qty: float, min_notional: float, maint_margin_rate: ?float,
     *         taker_fee_rate: float, slippage_buffer_pct: float, sl_liq_ratio: float, position_mode?: ?string, margin_type?: ?string}  $in
     * @return array<string, mixed>
     */
    public static function estimate(array $in): array
    {
        $dir = $in['direction'];
        $price = (float) $in['price'];
        $lev = (float) $in['leverage'];
        $noStop = (bool) ($in['no_stop_loss'] ?? false) || $in['sl_pct'] === null;
        $slPct = $noStop ? null : (float) $in['sl_pct'];
        $tpPct = $in['tp_pct'] !== null ? (float) $in['tp_pct'] : null;
        $tick = (float) $in['tick_size'];

        $qty = self::quantity((float) $in['stake'], $lev, $price, (float) $in['step_size']);
        $notional = round($qty * $price, 8);
        $margin = $lev > 0 ? round($notional / $lev, 8) : 0.0;

        $slTrigger = $noStop ? null : self::slTrigger($dir, $price, $slPct, $tick);
        $tpTrigger = $tpPct !== null ? self::tpTrigger($dir, $price, $tpPct, $tick) : null;

        $slMove = $slTrigger === null ? null : ($dir === 'long' ? $price - $slTrigger : $slTrigger - $price);   // adverse move in price units
        $slDistancePct = $slMove === null ? null : ($price > 0 ? $slMove / $price * 100 : 0.0);
        $rate = (float) $in['taker_fee_rate'];
        $entryFee = round($notional * $rate, 8);
        $exitFeeAtSl = $slTrigger === null ? null : round($qty * $slTrigger * $rate, 8);
        $grossLossAtSl = $slMove === null ? null : round($qty * $slMove, 8);
        $slippageRisk = round($notional * ((float) $in['slippage_buffer_pct']) / 100, 8);
        $netLossAtSl = $grossLossAtSl === null ? null : round($grossLossAtSl + $entryFee + $exitFeeAtSl, 8);

        $tp = null;
        if ($tpTrigger !== null) {
            $tpMove = $dir === 'long' ? $tpTrigger - $price : $price - $tpTrigger;
            $gross = round($qty * $tpMove, 8);
            $exitFee = round($qty * $tpTrigger * $rate, 8);
            $tp = [
                'trigger_price' => $tpTrigger,
                'distance_pct' => $price > 0 ? round($tpMove / $price * 100, 4) : 0.0,
                'gross_profit' => $gross,
                'exit_fee' => $exitFee,
                'net_profit' => round($gross - $entryFee - $exitFee, 8),
            ];
        }

        $errors = [];
        $warnings = [];

        if ($qty <= 0 || $qty < (float) $in['min_qty']) {
            $errors[] = ['code' => 'quantity_below_minimum', 'message' => 'Stake x leverage is too small for this symbol (quantity is below the exchange minimum).'];
        }
        if ((float) $in['min_notional'] > 0 && $notional < (float) $in['min_notional']) {
            $errors[] = ['code' => 'notional_below_minimum', 'message' => sprintf('Notional %.2f USDT is below the exchange minimum %.2f USDT.', $notional, (float) $in['min_notional'])];
        }
        if ($slTrigger !== null) {
            $slOnLossSide = $dir === 'long' ? $slTrigger < $price : $slTrigger > $price;
            if (! $slOnLossSide || $slMove <= 0) {
                $errors[] = ['code' => 'stop_loss_too_tight', 'message' => 'Stop-loss rounds to the entry price at this tick size. Use a wider stop-loss.'];
            }
        }
        if ($tpTrigger !== null) {
            $tpOnProfitSide = $dir === 'long' ? $tpTrigger > $price : $tpTrigger < $price;
            if (! $tpOnProfitSide) {
                $errors[] = ['code' => 'take_profit_too_tight', 'message' => 'Take-profit rounds to the entry price at this tick size. Use a wider take-profit.'];
            }
        }

        // Liquidation: estimate from the REAL maintenance-margin rate; fail closed if it is unknown.
        $liq = null;
        if ($in['maint_margin_rate'] === null) {
            $errors[] = ['code' => 'unable_to_verify_liquidation_distance', 'message' => 'Liquidation distance could not be verified from exchange leverage brackets. Refused.'];
        } else {
            $liq = (new LeverageValidationService())->estimateLiquidationDistance(
                $dir === 'long' ? 'buy' : 'sell',
                $price,
                $lev,
                (float) $in['maint_margin_rate'],
            );
            $ratio = (float) $in['sl_liq_ratio'];
            if ($noStop) {
                // No stop to compare: the leverage cap (normalizeInput) and the isolated-margin rule bound the risk.
                if ($liq['estimated_distance_pct'] <= 0) {
                    $errors[] = ['code' => 'unable_to_verify_liquidation_distance', 'message' => 'Liquidation distance could not be estimated. Refused.'];
                }
            } elseif ($liq['estimated_distance_pct'] <= 0 || $slDistancePct > $liq['estimated_distance_pct'] * $ratio) {
                $errors[] = ['code' => 'stop_loss_not_inside_liquidation', 'message' => sprintf(
                    'Stop-loss distance %.3f%% is not safely inside the estimated liquidation distance %.3f%% (must be at most %d%% of it). Lower the leverage or tighten the stop.',
                    $slDistancePct,
                    $liq['estimated_distance_pct'],
                    (int) round($ratio * 100),
                )];
            }
        }

        if ($noStop) {
            $warnings[] = 'NO STOP-LOSS: nothing closes this position automatically except liquidation. You must watch it and close it manually.';
            $warnings[] = sprintf('If price reaches the liquidation price you can lose your entire margin (%.2f USDT) and the position is force-closed by the exchange.', (float) $in['stake']);
        } else {
            $warnings[] = 'Stop-loss does not guarantee a maximum loss: it triggers a MARKET order, so gaps and fast markets can fill worse than the trigger.';
        }
        $warnings[] = 'Funding is charged/credited every 8 hours while the position is open and is not capped by the stop-loss.';
        $warnings[] = 'Liquidation price shown is an ESTIMATE; the exchange figure is authoritative.';
        $warnings[] = 'Exchange maintenance or connectivity problems can delay closing or reconciliation; protection lives on the exchange and does not depend on this page.';
        if (($in['margin_type'] ?? null) === 'cross') {
            $warnings[] = 'This symbol uses CROSS margin: liquidation depends on your whole futures wallet, not only this position.';
        }

        return [
            'direction' => $dir,
            'reference_price' => $price,
            'stake' => (float) $in['stake'],
            'leverage' => $lev,
            'quantity' => $qty,
            'notional' => $notional,
            'margin_required' => $margin,
            'no_stop_loss' => $noStop,
            'sl_pct' => $slPct,
            'sl_basis' => 'price_movement',
            'sl_trigger_price' => $slTrigger,
            'sl_distance_pct' => $slDistancePct === null ? null : round($slDistancePct, 4),
            'margin_loss_pct_at_sl' => $netLossAtSl === null ? null : ((float) $in['stake'] > 0 ? round($netLossAtSl / (float) $in['stake'] * 100, 4) : 0.0),
            'estimated_loss_at_sl' => $netLossAtSl,
            'gross_loss_at_sl' => $grossLossAtSl,
            'entry_fee_estimate' => $entryFee,
            'exit_fee_estimate_at_sl' => $exitFeeAtSl,
            'slippage_risk_estimate' => $slippageRisk,
            'take_profit' => $tp,
            // Approximate worst case without a stop: the isolated margin is lost (plus fees). Labelled an estimate in the UI.
            'max_loss_without_stop_estimate' => $noStop ? (float) $in['stake'] : null,
            'estimated_liquidation_price' => $liq['estimated_liquidation_price'] ?? null,
            'estimated_liquidation_distance_pct' => $liq['estimated_distance_pct'] ?? null,
            'position_mode' => $in['position_mode'] ?? null,
            'margin_type' => $in['margin_type'] ?? null,
            'errors' => $errors,
            'warnings' => $warnings,
            'ok' => $errors === [],
        ];
    }
}
