<?php

namespace App\Domain\Venue;

interface TradingVenueClientInterface
{
    public function provider(): string;

    public function getLatestPrice(string $assetCode): array;

    public function placeOrder(array $orderRequest): array;

    public function cancelOrder(string $orderId, ?string $assetCode = null): array;

    public function fetchOrder(string $orderId, ?string $assetCode = null): array;

    public function fetchBalances(): array;

    public function supportsOrderType(string $type): bool;

    public function supportsTimeInForce(string $tif): bool;
}

