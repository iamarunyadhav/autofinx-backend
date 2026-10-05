<?php

namespace App\Domain\RatioBot;

use App\Models\ExchangeSymbolMetadata;
use App\Models\Order;
use App\Models\RatioBotConfig;

/**
 * Small, pure-ish helpers that make the bot's bookkeeping match what the
 * exchange account really holds and earns:
 *  - a BUY whose commission was paid in the base coin leaves the account
 *    holding the filled qty MINUS that commission;
 *  - a realized trade costs fees on both legs;
 *  - anything left below the exchange's minimum tradable size is dust: it
 *    can never be sold, so it must not keep a position open.
 * Exchange limits come only from the locally cached symbol rules (no network
 * call in the trading path); without them the checks stay permissive and
 * LiveOrderIntentService's own validation remains the backstop.
 */
final class RatioBotFillAccounting
{
    public const MIN_DUST_QTY = 0.00000001;

    /** Quantity actually received for a BUY fill. */
    public static function netBuyQty(Order $order, RatioBotConfig $config, float $filledQty): float
    {
        $fee = (float) ($order->fee_amount ?? 0);
        $baseAsset = strtoupper((string) $config->baseAsset());
        if ($filledQty <= 0 || $fee <= 0 || $baseAsset === '' || strtoupper((string) $order->fee_asset) !== $baseAsset) {
            return $filledQty;
        }

        return round(max(0.0, $filledQty - $fee), 8);
    }

    /** Estimated fees of a round trip (both legs) at the bot's configured fee rate. */
    public static function roundTripFee(RatioBotConfig $config, float $entryPrice, float $exitPrice, float $qty): float
    {
        $feeBps = max(0.0, (float) ($config->fee_bps ?? 0));
        if ($feeBps <= 0 || $qty <= 0) {
            return 0.0;
        }

        return (max(0.0, $entryPrice) + max(0.0, $exitPrice)) * $qty * $feeBps / 10000;
    }

    public static function netRealizedPnl(RatioBotConfig $config, float $entryPrice, float $exitPrice, float $qty): float
    {
        return (($exitPrice - $entryPrice) * $qty) - self::roundTripFee($config, $entryPrice, $exitPrice, $qty);
    }

    /** @return array{min_qty: float, step_size: float, min_notional: float}|null */
    public static function rules(RatioBotConfig $config): ?array
    {
        if ($config->marketType() !== 'spot') {
            return null;
        }
        $rules = ExchangeSymbolMetadata::query()->where('exchange', 'binance')
            ->where('symbol', $config->normalizedSymbol())->first();
        if (! $rules) {
            return null;
        }

        return [
            'min_qty' => (float) ($rules->min_qty ?? 0),
            'step_size' => (float) ($rules->step_size ?? 0),
            'min_notional' => (float) ($rules->min_notional ?? 0),
        ];
    }

    /** Largest quantity that still cannot be sold on its own at this price. */
    public static function dustQty(RatioBotConfig $config, float $price): float
    {
        $rules = self::rules($config);
        if ($rules === null) {
            return self::MIN_DUST_QTY;
        }
        $byNotional = $price > 0 && $rules['min_notional'] > 0 ? $rules['min_notional'] / $price : 0.0;

        return max(self::MIN_DUST_QTY, $rules['min_qty'], $rules['step_size'], $byNotional);
    }

    /**
     * Why an order of this size would be refused by the exchange, or null.
     * Mirrors BinanceSpotSymbolService::validateForConnection() (step-size
     * round-down, then minQty and minNotional).
     */
    public static function belowExchangeMinimum(RatioBotConfig $config, float $qty, float $price): ?string
    {
        $rules = self::rules($config);
        if ($rules === null || $qty <= 0 || $price <= 0) {
            return null;
        }
        $step = $rules['step_size'];
        $normalized = $step > 0 ? floor(($qty + 1e-12) / $step) * $step : $qty;
        if ($normalized <= 0 || ($rules['min_qty'] > 0 && $normalized < $rules['min_qty'])) {
            return sprintf('quantity %.8f is below the exchange minimum %.8f', $normalized, max($rules['min_qty'], $step));
        }
        if ($rules['min_notional'] > 0 && $normalized * $price < $rules['min_notional']) {
            return sprintf('order value %.4f is below the exchange minimum %.4f', $normalized * $price, $rules['min_notional']);
        }

        return null;
    }
}
