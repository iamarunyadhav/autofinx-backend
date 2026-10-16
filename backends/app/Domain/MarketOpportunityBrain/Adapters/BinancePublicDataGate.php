<?php

namespace App\Domain\MarketOpportunityBrain\Adapters;

/**
 * Single choke point every real-network call in this module must pass through first.
 *
 * CONFIG_PROPOSAL.md:14 is explicit about the intended semantics: "MAX_DISCOVERY_SYMBOLS=0 and
 * MAX_API_WEIGHT_PER_MINUTE=0 mean the external poller remains disabled unless explicitly
 * configured." This class is that "consistent interpretation" the proposal asks the agent to
 * implement: `public_data_enabled` must be true AND both numeric budgets must be positive, or
 * every real adapter call fails closed with an explicit exception -- never a silent empty result
 * that could be mistaken for "no eligible symbols found."
 */
final class BinancePublicDataGate
{
    /**
     * @throws MarketDataDisabledException
     */
    public static function assertEnabled(): void
    {
        if (! (bool) config('market_opportunity_brain.public_data_enabled', false)) {
            throw new MarketDataDisabledException('market_opportunity_brain.public_data_enabled is false; real Binance public-data calls are disabled by default.');
        }
        if ((int) config('market_opportunity_brain.max_api_weight_per_minute', 0) <= 0) {
            throw new MarketDataDisabledException('market_opportunity_brain.max_api_weight_per_minute is 0; the external poller stays disabled until explicitly configured.');
        }
        if ((int) config('market_opportunity_brain.max_discovery_symbols', 0) <= 0) {
            throw new MarketDataDisabledException('market_opportunity_brain.max_discovery_symbols is 0; dynamic discovery stays disabled until explicitly configured.');
        }
    }

    public static function isEnabled(): bool
    {
        try {
            self::assertEnabled();

            return true;
        } catch (MarketDataDisabledException) {
            return false;
        }
    }
}
