<?php

namespace App\Domain\Venue;

use App\Domain\Exchange\ExchangeClientInterface;
use App\Domain\Exchange\BinanceSymbolNormalizer;
use RuntimeException;

class BinanceTradingVenueClient implements TradingVenueClientInterface
{
    public function __construct(private readonly ExchangeClientInterface $client)
    {
    }

    public function provider(): string
    {
        return 'binance';
    }

    public function getLatestPrice(string $assetCode): array
    {
        $normalizedAssetCode = BinanceSymbolNormalizer::normalize($assetCode);
        $price = (float) $this->client->getLatestPrice($normalizedAssetCode);

        return [
            'asset_code' => $normalizedAssetCode,
            'provider' => $this->provider(),
            'bid' => $price,
            'ask' => $price,
            'mid' => $price,
            'spread_pct' => 0.0,
        ];
    }

    public function placeOrder(array $orderRequest): array
    {
        $assetCode = BinanceSymbolNormalizer::normalize((string) ($orderRequest['asset_code'] ?? ''));
        $side = strtoupper((string) ($orderRequest['side'] ?? 'BUY'));
        $type = strtoupper((string) ($orderRequest['type'] ?? 'LIMIT'));
        $tif = strtoupper((string) ($orderRequest['tif'] ?? 'IOC'));
        $qty = (float) ($orderRequest['qty'] ?? 0);
        $price = (float) ($orderRequest['price'] ?? 0);
        $clientOrderId = isset($orderRequest['client_order_id']) ? (string) $orderRequest['client_order_id'] : null;
        if ($assetCode === '' || $qty <= 0) {
            throw new RuntimeException('Invalid Binance order request');
        }

        if ($type === 'OCO') {
            $tp = (float) ($orderRequest['take_profit_price'] ?? $price);
            $stopPrice = (float) ($orderRequest['stop_price'] ?? 0);
            $stopLimit = (float) ($orderRequest['stop_limit_price'] ?? 0);
            $order = $this->client->placeOCOOrder($assetCode, $side, $qty, $tp, $stopPrice, $stopLimit, $clientOrderId);
            return $this->normalize($assetCode, $order->toArray());
        }

        if ($type === 'MARKET') {
            $order = $this->client->placeMarketOrder($assetCode, $side, $qty, $clientOrderId);

            return $this->normalize($assetCode, $order->toArray());
        }

        if ($type === 'LIMIT' && $tif === 'IOC') {
            $order = $this->client->placeLimitIOCOrder($assetCode, $side, $qty, $price, $clientOrderId);
            return $this->normalize($assetCode, $order->toArray());
        }

        // Fallback for unsupported combos uses IOC limit at provided or latest price.
        $fallbackPrice = $price > 0 ? $price : (float) $this->client->getLatestPrice($assetCode);
        $order = $this->client->placeLimitIOCOrder($assetCode, $side, $qty, $fallbackPrice, $clientOrderId);

        return $this->normalize($assetCode, $order->toArray());
    }

    public function cancelOrder(string $orderId, ?string $assetCode = null): array
    {
        if (! $assetCode) {
            throw new RuntimeException('asset_code is required to cancel Binance order');
        }

        return $this->client->cancelOrder(BinanceSymbolNormalizer::normalize($assetCode), $orderId);
    }

    public function fetchOrder(string $orderId, ?string $assetCode = null): array
    {
        if (! $assetCode) {
            throw new RuntimeException('asset_code is required to fetch Binance order');
        }
        $normalizedAssetCode = BinanceSymbolNormalizer::normalize($assetCode);
        $order = $this->client->fetchOrder($normalizedAssetCode, $orderId);

        return $this->normalize($normalizedAssetCode, $order->toArray());
    }

    public function fetchBalances(): array
    {
        return $this->client->fetchBalances();
    }

    public function supportsOrderType(string $type): bool
    {
        return in_array(strtoupper($type), ['LIMIT', 'OCO', 'MARKET'], true);
    }

    public function supportsTimeInForce(string $tif): bool
    {
        return in_array(strtoupper($tif), ['IOC', 'FOK', 'GTC'], true);
    }

    private function normalize(string $assetCode, array $payload): array
    {
        $raw = (array) ($payload['raw'] ?? $payload);
        $fills = (array) ($raw['fills'] ?? []);

        return [
            'order_id' => (string) ($payload['orderId'] ?? $raw['orderId'] ?? $raw['orderListId'] ?? ''),
            'asset_code' => $assetCode,
            'provider' => $this->provider(),
            'status' => strtoupper((string) ($payload['status'] ?? $raw['status'] ?? $raw['listStatusType'] ?? 'NEW')),
            'executed_qty' => (float) ($payload['executedQty'] ?? $raw['executedQty'] ?? 0),
            'orig_qty' => (float) ($payload['origQty'] ?? $raw['origQty'] ?? $raw['quantity'] ?? 0),
            'avg_price' => (float) ($payload['price'] ?? $raw['price'] ?? 0),
            'client_order_id' => (string) ($raw['clientOrderId'] ?? $raw['listClientOrderId'] ?? ''),
            'fills' => $fills,
            'raw' => $raw,
        ];
    }
}
