<?php

namespace App\Domain\Billing\Gateways;

use Illuminate\Http\Request;

interface GatewayInterface
{
    public function initiateTopup(array $payload): array;

    public function handleWebhook(Request $request): array;

    public function fetchPaymentStatus(string $externalId): array;
}
