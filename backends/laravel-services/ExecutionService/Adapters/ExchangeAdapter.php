<?php

namespace Services\ExecutionService\Adapters;

interface ExchangeAdapter
{
    public function fetchTicker(string $symbol): array;

    public function fetchCandles(string $symbol, string $interval = '1m', int $limit = 50): array;

    public function placeOrder(array $params): array;

    public function cancelOrder(string $orderId, ?string $symbol = null): array;

    public function fetchOrderStatus(string $orderId, ?string $symbol = null): string;
}
