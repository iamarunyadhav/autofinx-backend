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
     * @return array{allowed: bool, real_max_for_notional: ?float, reason: ?string}
     */
    public function validate(BinanceFuturesClient $client, string $symbol, float $requestedLeverage, float $notional): array
    {
        if ($requestedLeverage <= 0) {
            return [
                'allowed' => false,
                'real_max_for_notional' => null,
                'reason' => 'invalid_requested_leverage',
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
            ];
        }

        if ($brackets === null || $brackets === []) {
            return [
                'allowed' => false,
                'real_max_for_notional' => null,
                'reason' => self::REASON_UNABLE_TO_VERIFY,
            ];
        }

        $tier = $this->tierForNotional($brackets, $notional);
        $realMax = $tier !== null ? (float) ($tier['initialLeverage'] ?? 0) : 0.0;

        if ($realMax <= 0) {
            return [
                'allowed' => false,
                'real_max_for_notional' => null,
                'reason' => self::REASON_UNABLE_TO_VERIFY,
            ];
        }

        if ($requestedLeverage > $realMax + 1e-9) {
            return [
                'allowed' => false,
                'real_max_for_notional' => $realMax,
                'reason' => self::REASON_EXCEEDS_MAX,
            ];
        }

        return [
            'allowed' => true,
            'real_max_for_notional' => $realMax,
            'reason' => null,
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
