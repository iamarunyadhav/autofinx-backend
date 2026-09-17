<?php

namespace App\Domain\Trading\Providers;

use App\Models\Integration;

interface LiveTradeProviderInterface
{
    public function providerKey(): string;

    public function capabilities(): array;

    public function latestQuote(?Integration $integration, string $symbol): array;

    public function candles(?Integration $integration, string $symbol, string $timeframe, int $limit = 120): array;

    public function availableBalance(Integration $integration, string $asset = 'USDT'): array;

    /**
     * @param bool $leverageEnabled When true, the provider must verify
     *   $leverage against the real exchange max for the resulting notional
     *   (via LeverageValidationService) BEFORE placing any order, reject the
     *   trade outright if it exceeds that real max, and only then call the
     *   exchange's real "set leverage" endpoint. When false, behavior must be
     *   byte-for-byte identical to before this parameter existed.
     */
    public function openTimedTrade(
        Integration $integration,
        string $symbol,
        string $side,
        float $stakeAmount,
        string $idempotencyKey,
        bool $leverageEnabled = false,
        ?float $leverage = null,
    ): array;

    public function closeTimedTrade(
        Integration $integration,
        string $symbol,
        string $entrySide,
        float $quantity,
        string $idempotencyKey
    ): array;
}
