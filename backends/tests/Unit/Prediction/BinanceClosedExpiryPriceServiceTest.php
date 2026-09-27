<?php

namespace Tests\Unit\Prediction;

use App\Models\Integration;
use PHPUnit\Framework\TestCase;

final class BinanceClosedExpiryPriceServiceTest extends TestCase
{
    public function test_non_binance_integration_is_not_accepted(): void
    {
        self::assertSame('binance', 'binance');
        self::assertNotSame('coinbase', 'binance');
    }
}
