<?php

namespace App\Domain\Venue;

use App\Domain\Exchange\BinanceConvertClient;
use App\Domain\Exchange\ExchangeClientInterface;
use App\Domain\Exchange\BinanceSymbolNormalizer;
use RuntimeException;

class BinanceTradingVenueClient implements TradingVenueClientInterface
{
    public function __construct(
        private readonly ExchangeClientInterface $client,
        private readonly ?BinanceConvertClient $convert = null,
    ) {
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

        if ($type === 'CONVERT') {
            return $this->placeConvertOrder($assetCode, $side, $qty, $price, $clientOrderId, (float) ($orderRequest['max_quote_deviation_bps'] ?? 0));
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
        return in_array(strtoupper($type), ['LIMIT', 'OCO', 'MARKET', 'CONVERT'], true);
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

    private function placeConvertOrder(string $assetCode, string $side, float $qty, float $referencePrice, ?string $clientOrderId, float $maxDeviationBps): array
    {
        if (! $this->convert) {
            throw new RuntimeException('Binance Convert execution requires a live Spot connection.');
        }

        [$baseAsset, $quoteAsset] = $this->splitSymbolAssets($assetCode);
        if (! $baseAsset || ! $quoteAsset) {
            throw new RuntimeException("Unable to map {$assetCode} into Convert assets.");
        }

        $fromAsset = $side === 'BUY' ? $quoteAsset : $baseAsset;
        $toAsset = $side === 'BUY' ? $baseAsset : $quoteAsset;
        $fromAmount = $side === 'BUY' ? $qty * $referencePrice : $qty;
        $quote = $this->convert->getQuote($fromAsset, $toAsset, $fromAmount);
        $quoteRate = $this->convertQuotePrice($quote, $side);
        $maxDeviationBps = max(0.0, $maxDeviationBps);

        if ($referencePrice > 0 && $quoteRate > 0 && $maxDeviationBps > 0) {
            $deviationBps = abs(($quoteRate - $referencePrice) / $referencePrice) * 10000;
            if ($deviationBps > $maxDeviationBps) {
                throw new RuntimeException(sprintf(
                    'Binance Convert quote deviated %.4f bps from reference price; max allowed is %.4f bps.',
                    $deviationBps,
                    $maxDeviationBps
                ));
            }
        }

        $accepted = $this->convert->acceptQuote((string) $quote['quoteId']);
        $status = strtoupper((string) ($accepted['orderStatus'] ?? 'PROCESS'));
        $orderId = (string) ($accepted['orderId'] ?? '');
        $final = $status === 'SUCCESS'
            ? $accepted + $quote
            : $this->pollConvertStatus($orderId, (string) $quote['quoteId'], $accepted + $quote);

        return $this->normalizeConvert($assetCode, $side, $qty, $referencePrice, $clientOrderId, $quote, $accepted, $final);
    }

    private function pollConvertStatus(string $orderId, string $quoteId, array $fallback): array
    {
        for ($i = 0; $i < 3; $i++) {
            usleep(300_000);
            $status = $this->convert?->orderStatus($orderId !== '' ? $orderId : null, $quoteId);
            if (is_array($status) && strtoupper((string) ($status['orderStatus'] ?? '')) === 'SUCCESS') {
                return $status;
            }
        }

        return $fallback;
    }

    private function normalizeConvert(string $assetCode, string $side, float $requestedQty, float $referencePrice, ?string $clientOrderId, array $quote, array $accepted, array $final): array
    {
        [$baseAsset, $quoteAsset] = $this->splitSymbolAssets($assetCode);
        $orderStatus = strtoupper((string) ($final['orderStatus'] ?? $accepted['orderStatus'] ?? 'PROCESS'));
        $executedQty = $side === 'BUY' ? (float) ($final['toAmount'] ?? $quote['toAmount'] ?? 0) : (float) ($final['fromAmount'] ?? $quote['fromAmount'] ?? $requestedQty);
        $avgPrice = $this->convertQuotePrice($final + $quote, $side);
        $orderId = (string) ($final['orderId'] ?? $accepted['orderId'] ?? $quote['quoteId'] ?? '');
        $eventTime = (int) ($final['createTime'] ?? $accepted['createTime'] ?? floor(microtime(true) * 1000));
        $status = $orderStatus === 'SUCCESS' ? 'FILLED' : ($orderStatus === 'FAIL' ? 'REJECTED' : 'NEW');

        $raw = [
            'orderId' => $orderId,
            'clientOrderId' => $clientOrderId,
            'symbol' => $assetCode,
            'side' => $side,
            'type' => 'CONVERT',
            'status' => $status,
            'executedQty' => $executedQty,
            'price' => $avgPrice,
            'quote' => $quote,
            'accepted' => $accepted,
            'status_payload' => $final,
        ];

        return [
            'order_id' => $orderId,
            'asset_code' => $assetCode,
            'provider' => $this->provider(),
            'status' => $status,
            'executed_qty' => $executedQty,
            'orig_qty' => $requestedQty,
            'avg_price' => $avgPrice,
            'client_order_id' => (string) $clientOrderId,
            'fills' => [[
                'tradeId' => $orderId !== '' ? 'convert-' . $orderId : 'convert-' . (string) ($quote['quoteId'] ?? ''),
                'price' => $avgPrice,
                'qty' => $executedQty,
                'commission' => 0,
                'commissionAsset' => $quoteAsset ?: '',
                'time' => $eventTime,
                'fromAsset' => $final['fromAsset'] ?? null,
                'fromAmount' => $final['fromAmount'] ?? null,
                'toAsset' => $final['toAsset'] ?? null,
                'toAmount' => $final['toAmount'] ?? null,
            ]],
            'raw' => $raw,
        ];
    }

    private function convertQuotePrice(array $payload, string $side): float
    {
        $fromAmount = (float) ($payload['fromAmount'] ?? 0);
        $toAmount = (float) ($payload['toAmount'] ?? 0);
        if ($side === 'BUY' && $fromAmount > 0 && $toAmount > 0) {
            return $fromAmount / $toAmount;
        }
        if ($side === 'SELL' && $fromAmount > 0 && $toAmount > 0) {
            return $toAmount / $fromAmount;
        }

        return (float) ($payload['ratio'] ?? $payload['inverseRatio'] ?? 0);
    }

    private function splitSymbolAssets(string $symbol): array
    {
        foreach (['USDT', 'USDC', 'FDUSD', 'BUSD', 'BTC', 'ETH', 'BNB'] as $quote) {
            if (str_ends_with($symbol, $quote) && strlen($symbol) > strlen($quote)) {
                return [substr($symbol, 0, -strlen($quote)), $quote];
            }
        }

        return [null, null];
    }
}
