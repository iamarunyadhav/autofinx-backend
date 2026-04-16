<?php

namespace App\Domain\Exchange;

use App\Domain\Exchange\DTO\ExchangeOrder;

interface ExchangeClientInterface
{
    public function getLatestPrice(string $symbol): float;

    public function placeLimitIOCOrder(
        string $symbol,
        string $side,
        float $qty,
        float $limitPrice,
        ?string $clientOrderId = null
    ): ExchangeOrder;

    public function placeOCOOrder(
        string $symbol,
        string $side,
        float $qty,
        float $limitPrice,
        float $stopPrice,
        float $stopLimitPrice,
        ?string $clientOrderId = null
    ): ExchangeOrder;

    public function cancelOrder(string $symbol, string $orderId): array;

    public function fetchOrder(string $symbol, string $orderId): ExchangeOrder;

    public function fetchBalances(): array;
}
