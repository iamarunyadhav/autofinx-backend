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

    public function openTimedTrade(
        Integration $integration,
        string $symbol,
        string $side,
        float $stakeAmount,
        string $idempotencyKey
    ): array;

    public function closeTimedTrade(
        Integration $integration,
        string $symbol,
        string $entrySide,
        float $quantity,
        string $idempotencyKey
    ): array;
}
