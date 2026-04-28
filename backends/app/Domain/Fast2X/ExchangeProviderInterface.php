<?php

namespace App\Domain\Fast2X;

interface ExchangeProviderInterface
{
    public function connectWebSocket(string $symbol): void;
    public function disconnectWebSocket(): void;
    public function onPriceUpdate(callable $callback): void;
    public function placeMarketBuy(string $symbol, float $amount): array;
    public function placeMarketSell(string $symbol, float $quantity): array;
    public function getBalance(string $asset): array;
    public function getOrderStatus(string $orderId): array;
    public function checkExchangeHealth(): array;
    public function measureLatency(): int;
}
