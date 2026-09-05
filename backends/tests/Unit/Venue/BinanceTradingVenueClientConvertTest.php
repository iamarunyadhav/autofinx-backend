<?php

namespace Tests\Unit\Venue;

use App\Domain\Exchange\BinanceConvertClient;
use App\Domain\Exchange\DTO\ExchangeOrder;
use App\Domain\Exchange\ExchangeClientInterface;
use App\Domain\Venue\BinanceTradingVenueClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BinanceTradingVenueClientConvertTest extends TestCase
{
    public function test_convert_buy_normalizes_successful_quote_into_fill_payload(): void
    {
        $convert = new class extends BinanceConvertClient {
            public bool $accepted = false;

            public function __construct()
            {
            }

            public function getQuote(string $fromAsset, string $toAsset, float $fromAmount, string $validTime = '10s'): array
            {
                return [
                    'quoteId' => 'quote-1',
                    'fromAmount' => '100',
                    'toAmount' => '0.001',
                    'ratio' => '0.00001',
                    'inverseRatio' => '100000',
                ];
            }

            public function acceptQuote(string $quoteId): array
            {
                $this->accepted = true;

                return ['orderId' => 'convert-1', 'orderStatus' => 'SUCCESS', 'createTime' => 1000];
            }
        };

        $venue = new BinanceTradingVenueClient(new FakeExchangeClient(), $convert);
        $result = $venue->placeOrder([
            'asset_code' => 'BTCUSDT',
            'side' => 'BUY',
            'type' => 'CONVERT',
            'qty' => 0.001,
            'price' => 100000,
            'client_order_id' => 'rb-test',
            'max_quote_deviation_bps' => 1,
        ]);

        $this->assertTrue($convert->accepted);
        $this->assertSame('FILLED', $result['status']);
        $this->assertSame(0.001, $result['executed_qty']);
        $this->assertSame(100000.0, $result['avg_price']);
        $this->assertSame('rb-test', $result['client_order_id']);
        $this->assertSame('CONVERT', $result['raw']['type']);
        $this->assertCount(1, $result['fills']);
    }

    public function test_convert_quote_deviation_blocks_before_accepting_quote(): void
    {
        $convert = new class extends BinanceConvertClient {
            public bool $accepted = false;

            public function __construct()
            {
            }

            public function getQuote(string $fromAsset, string $toAsset, float $fromAmount, string $validTime = '10s'): array
            {
                return [
                    'quoteId' => 'quote-1',
                    'fromAmount' => '90',
                    'toAmount' => '0.001',
                    'ratio' => '0.00001111',
                    'inverseRatio' => '90000',
                ];
            }

            public function acceptQuote(string $quoteId): array
            {
                $this->accepted = true;

                return [];
            }
        };

        $venue = new BinanceTradingVenueClient(new FakeExchangeClient(), $convert);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Binance Convert quote deviated');

        try {
            $venue->placeOrder([
                'asset_code' => 'BTCUSDT',
                'side' => 'BUY',
                'type' => 'CONVERT',
                'qty' => 0.001,
                'price' => 100000,
                'max_quote_deviation_bps' => 50,
            ]);
        } finally {
            $this->assertFalse($convert->accepted);
        }
    }
}

class FakeExchangeClient implements ExchangeClientInterface
{
    public function getLatestPrice(string $symbol): float
    {
        return 100000.0;
    }

    public function placeLimitIOCOrder(string $symbol, string $side, float $qty, float $limitPrice, ?string $clientOrderId = null): ExchangeOrder
    {
        throw new RuntimeException('unused');
    }

    public function placeOCOOrder(string $symbol, string $side, float $qty, float $limitPrice, float $stopPrice, float $stopLimitPrice, ?string $clientOrderId = null): ExchangeOrder
    {
        throw new RuntimeException('unused');
    }

    public function cancelOrder(string $symbol, string $orderId): array
    {
        throw new RuntimeException('unused');
    }

    public function fetchOrder(string $symbol, string $orderId): ExchangeOrder
    {
        throw new RuntimeException('unused');
    }

    public function fetchBalances(): array
    {
        return [];
    }
}
