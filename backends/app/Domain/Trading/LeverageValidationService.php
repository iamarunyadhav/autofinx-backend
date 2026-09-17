<?php

namespace App\Domain\Trading;

use App\Domain\Exchange\BinanceFuturesClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single source of truth for "is this leverage actually allowed by the real
 * exchange for this position's notional" — used identically by Quick Trade
 * and Future Trade before ANY leverage-bearing live order is placed.
 *
 * Binance's real leverage ceiling is tiered by notional value (e.g. a symbol
 * might allow 125x only below a certain position size, less above it), so a
 * requested leverage must be checked against the tier its OWN notional falls
 * into — never against the symbol's absolute best-case tier.
 *
 * Fails CLOSED: if the real tier table can't be fetched or doesn't parse into
 * the expected shape, this returns `allowed: false`. It never falls back to a
 * locally-configured "max leverage" as if it were exchange-verified — that
 * would defeat the entire point of this service.
 */
class LeverageValidationService
{
    /**
     * Leverage above this level requires the caller to also have a stop-loss
     * configured on the same position. Documented, explicit constant — not
     * pulled from config, so it can't be silently loosened by a settings
     * change without a code review.
     */
    public const HIGH_LEVERAGE_STOP_LOSS_THRESHOLD = 20.0;

    public const REASON_UNABLE_TO_VERIFY = 'unable_to_verify_exchange_max_leverage';

    public const REASON_EXCEEDS_MAX = 'exceeds_exchange_max_leverage_for_notional';

    /**
     * Below this estimated distance-to-liquidation (as a percent of entry
     * price), a leveraged trade is rejected pre-trade regardless of leverage
     * bracket compliance. Documented, explicit constant (not config) for the
     * same reason `HIGH_LEVERAGE_STOP_LOSS_THRESHOLD` is: it must not be
     * silently loosened by a settings change without a code review. 3% was
     * chosen because this platform's own live-position exit monitoring
     * (`CheckQuickTradeStopLossJob`, ~2s tick) plus real-world fill
     * slippage/latency could plausibly consume a couple of percent of price
     * movement before an operator or automated exit reacts — a distance
     * below that floor leaves too little room for this app's own safety nets
     * to act before the exchange itself would liquidate the position.
     */
    public const MIN_LIQUIDATION_DISTANCE_PCT = 3.0;

    /**
     * @return array{allowed: bool, real_max_for_notional: ?float, reason: ?string, maint_margin_rate: ?float}
     */
    public function validate(BinanceFuturesClient $client, string $symbol, float $requestedLeverage, float $notional): array
    {
        if ($requestedLeverage <= 0) {
            return [
                'allowed' => false,
                'real_max_for_notional' => null,
                'reason' => 'invalid_requested_leverage',
                'maint_margin_rate' => null,
            ];
        }

        try {
            $brackets = $client->leverageBrackets($symbol);
        } catch (Throwable $e) {
            Log::warning('leverage_validation_bracket_lookup_failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage(),
            ]);

            return [
                'allowed' => false,
                'real_max_for_notional' => null,
                'reason' => self::REASON_UNABLE_TO_VERIFY,
                'maint_margin_rate' => null,
            ];
        }

        if ($brackets === null || $brackets === []) {
            return [
                'allowed' => false,
                'real_max_for_notional' => null,
                'reason' => self::REASON_UNABLE_TO_VERIFY,
                'maint_margin_rate' => null,
            ];
        }

        $tier = $this->tierForNotional($brackets, $notional);
        $realMax = $tier !== null ? (float) ($tier['initialLeverage'] ?? 0) : 0.0;
        // Additive capture only — used solely by the liquidation-distance
        // estimate below/downstream, never by the leverage-ceiling check
        // itself. Null (not 0.0) when Binance's response for this tier
        // didn't include it, so a caller relying on it can tell "genuinely
        // zero" apart from "unknown" and fail closed accordingly.
        $maintMarginRate = ($tier !== null && array_key_exists('maintMarginRatio', $tier) && is_numeric($tier['maintMarginRatio']))
            ? (float) $tier['maintMarginRatio']
            : null;

        if ($realMax <= 0) {
            return [
                'allowed' => false,
                'real_max_for_notional' => null,
                'reason' => self::REASON_UNABLE_TO_VERIFY,
                'maint_margin_rate' => null,
            ];
        }

        if ($requestedLeverage > $realMax + 1e-9) {
            return [
                'allowed' => false,
                'real_max_for_notional' => $realMax,
                'reason' => self::REASON_EXCEEDS_MAX,
                'maint_margin_rate' => $maintMarginRate,
            ];
        }

        return [
            'allowed' => true,
            'real_max_for_notional' => $realMax,
            'reason' => null,
            'maint_margin_rate' => $maintMarginRate,
        ];
    }

    /**
     * Standard USDS-M isolated-margin approximation of a leveraged position's
     * liquidation price/distance. This is an ESTIMATE, not Binance's own
     * authoritative figure (real liquidation math also accounts for fees,
     * funding, and cross-vs-isolated margin-mode nuances this session had no
     * live API/docs access to verify exactly) — every field this method
     * returns is named `estimated_*` for that reason, and callers must never
     * relabel it as a bare "liquidation_price".
     *
     * Sign convention (verified to make directional sense): a long position's
     * liquidation price sits BELOW entry (price falling erodes margin), a
     * short's sits ABOVE entry (price rising erodes margin).
     *
     * `entryPrice` here is expected to be the current live quote used as an
     * entry-price proxy — the real fill price may differ slightly by the
     * time the market order actually executes.
     *
     * @return array{estimated_liquidation_price: float, estimated_distance_pct: float}
     */
    public function estimateLiquidationDistance(string $side, float $entryPrice, float $leverage, float $maintMarginRate): array
    {
        $side = strtolower($side);
        $inverseLeverage = $leverage > 0 ? (1 / $leverage) : 1.0;

        $estimatedLiquidationPrice = $side === 'sell'
            // Short: liquidation ABOVE entry.
            ? $entryPrice * (1 + $inverseLeverage - $maintMarginRate)
            // Long (default/buy): liquidation BELOW entry.
            : $entryPrice * (1 - $inverseLeverage + $maintMarginRate);

        $estimatedLiquidationPrice = max(0.0, $estimatedLiquidationPrice);
        $estimatedDistancePct = $entryPrice > 0
            ? abs($entryPrice - $estimatedLiquidationPrice) / $entryPrice * 100
            : 0.0;

        return [
            'estimated_liquidation_price' => round($estimatedLiquidationPrice, 8),
            'estimated_distance_pct' => round($estimatedDistancePct, 4),
        ];
    }

    /**
     * Finds the bracket tier whose [notionalFloor, notionalCap) range contains
     * the given notional. If the notional exceeds every tier's cap (an
     * unusually large position), the highest-notional tier is used — it's the
     * most conservative (lowest leverage) tier Binance publishes, so treating
     * an out-of-range notional as governed by it is fail-closed, not
     * permissive.
     *
     * @param array<int, array{initialLeverage: int|float, notionalCap: int|float, notionalFloor: int|float}> $brackets
     */
    private function tierForNotional(array $brackets, float $notional): ?array
    {
        foreach ($brackets as $bracket) {
            $floor = (float) ($bracket['notionalFloor'] ?? 0);
            $cap = (float) ($bracket['notionalCap'] ?? 0);
            if ($cap <= 0) {
                continue;
            }
            if ($notional >= $floor && $notional < $cap) {
                return $bracket;
            }
        }

        $sorted = $brackets;
        usort($sorted, fn ($a, $b) => (float) ($b['notionalCap'] ?? 0) <=> (float) ($a['notionalCap'] ?? 0));

        return $sorted[0] ?? null;
    }
}
