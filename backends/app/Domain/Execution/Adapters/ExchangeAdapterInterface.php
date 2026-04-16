<?php

namespace App\Domain\Execution\Adapters;

interface ExchangeAdapterInterface
{
    public function testConnection(array $credentials): array;

    public function fetchTicker(string $symbol): array;

    public function placeOrder(string $symbol, string $side, float $qty, string $type, string $idempotencyKey): array;

    public function cancelOrder(string $externalOrderId): array;
}
