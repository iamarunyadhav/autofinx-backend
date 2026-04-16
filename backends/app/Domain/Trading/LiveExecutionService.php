<?php

namespace App\Domain\Trading;

use App\Models\Order;

class LiveExecutionService
{
    public function __construct(
        private readonly LiveOrderIntentService $orders,
        private readonly LiveOrderCancellationService $cancellations,
    ) {
    }

    public function placeOrder(array $payload): Order
    {
        return $this->orders->place($payload);
    }

    public function cancelOrder(string $workspaceId, Order $order): Order
    {
        return $this->cancellations->cancel($workspaceId, $order);
    }
}
