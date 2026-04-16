<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Gateways\GatewayInterface;
use App\Domain\Billing\Gateways\PayHereGateway;
use App\Domain\Billing\Gateways\PayPalGateway;
use App\Domain\Billing\Gateways\PayoneerGateway;
use App\Domain\Billing\Gateways\StripeGateway;
use Illuminate\Http\Request;
use RuntimeException;

class GatewayRouter
{
    private array $map;

    public function __construct()
    {
        $this->map = [
            'payhere' => new PayHereGateway(),
            'paypal' => new PayPalGateway(),
            'payoneer' => new PayoneerGateway(),
            'stripe' => new StripeGateway(),
        ];
    }

    public function gateway(string $name): GatewayInterface
    {
        $key = strtolower($name);
        if (!isset($this->map[$key])) {
            throw new RuntimeException("Gateway {$name} not supported");
        }
        return $this->map[$key];
    }

    public function initiate(string $gateway, array $payload): array
    {
        $gw = $this->gateway($gateway);
        if (!$this->isEnabled($gateway)) {
            throw new RuntimeException("Gateway {$gateway} disabled");
        }
        return $gw->initiateTopup($payload);
    }

    public function webhook(string $gateway, Request $request): array
    {
        $gw = $this->gateway($gateway);
        return $gw->handleWebhook($request);
    }

    public function fetchStatus(string $gateway, string $externalId): array
    {
        $gw = $this->gateway($gateway);
        return $gw->fetchPaymentStatus($externalId);
    }

    private function isEnabled(string $gateway): bool
    {
        $key = strtoupper($gateway) . '_ENABLED';
        return (bool) env($key, $gateway === env('GATEWAY_DEFAULT', 'payhere'));
    }
}
